<?php
/**
 * Rewriting URLs inside restored data, including serialized values.
 *
 * @package RestorePilot_Backup_Migration
 */

if (!defined('ABSPATH')) {
  exit;
}

trait RestorePilot_Migration {
  private static function replace_urls_deep($value, string $source_url, string $target_url) {
    if ($source_url === '' || $target_url === '' || $source_url === $target_url) {
      return $value;
    }

    if (is_string($value)) {
      // Only attempt the serialized path when the value is a serialized array
      // or object — never for scalar serialized forms like `i:42;` or `b:1;`,
      // which would unserialize to a PHP scalar, then maybe_serialize back as a
      // plain string, silently corrupting the stored value.
      if (is_serialized($value)) {
        // allowed_classes => false: never instantiate a PHP class from a backup
        // archive. Archive contents are untrusted input (a backup may come from
        // another site or an unknown source), and instantiating arbitrary classes
        // here would allow object injection via a crafted serialized payload.
        // With object instantiation disabled, every serialized object decodes to
        // __PHP_Incomplete_Class, which contains_incomplete_object() detects
        // below — so object payloads are preserved byte-for-byte instead of being
        // rewritten, while arrays and scalars still get URL replacement.
        $maybe = @unserialize($value, ['allowed_classes' => false]);
        if (is_array($maybe) || is_object($maybe)) {
          if (self::contains_incomplete_object($maybe)) {
            self::write_log('Skipped URL replacement inside a serialized value containing a PHP object; the original value was preserved unchanged.');
            return $value;
          }
          $replaced = self::replace_urls_deep($maybe, $source_url, $target_url);
          // Only re-serialize if something actually changed; otherwise return
          // the original bytes so non-canonical serialized strings are not
          // altered unnecessarily.
          return $replaced === $maybe ? $value : maybe_serialize($replaced);
        }
        // Scalar serialized value (i:N, d:N, b:N, N;) — can never contain a
        // URL; return unchanged.
        return $value;
      }
      [$search, $replace] = self::url_replacement_pairs($source_url, $target_url);
      return str_replace($search, $replace, $value);
    }

    if (is_array($value)) {
      foreach ($value as $k => $v) {
        $value[$k] = self::replace_urls_deep($v, $source_url, $target_url);
      }
      return $value;
    }

    if (is_object($value)) {
      if (self::is_incomplete_object($value)) {
        return $value;
      }
      foreach (get_object_vars($value) as $k => $v) {
        $value->$k = self::replace_urls_deep($v, $source_url, $target_url);
      }
      return $value;
    }

    return $value;
  }

  private static function contains_incomplete_object($value): bool {
    if (is_object($value)) {
      if (self::is_incomplete_object($value)) {
        return true;
      }

      foreach (get_object_vars($value) as $property) {
        if (self::contains_incomplete_object($property)) {
          return true;
        }
      }
      return false;
    }

    if (is_array($value)) {
      foreach ($value as $item) {
        if (self::contains_incomplete_object($item)) {
          return true;
        }
      }
    }

    return false;
  }

  private static function is_incomplete_object($value): bool {
    return is_object($value) && get_class($value) === '__PHP_Incomplete_Class';
  }

  private static function url_replacement_pairs(string $source_url, string $target_url): array {
    $source = rtrim(self::normalize_url($source_url), '/');
    $target = rtrim(self::normalize_url($target_url), '/');
    $source_no_scheme = rtrim((string) preg_replace('#^https?://#i', '', $source), '/');
    $target_no_scheme = rtrim((string) preg_replace('#^https?://#i', '', $target), '/');

    if ($source_no_scheme === '') {
      return [[], []];
    }

    // Build explicit scheme-prefixed pairs only. A bare domain replacement
    // (e.g. "old.com" → "new.com") matches inside email addresses, longer
    // domain names, and any substring — so it is intentionally omitted.
    //
    // Escaped-slash variants (https:\/\/) cover Gutenberg block markup stored
    // in post_content as JSON, where forward slashes are backslash-escaped.
    // That content is not serialized, so it takes the plain str_replace path.
    $candidates = [
      ['https://' . $source_no_scheme . '/', 'https://' . $target_no_scheme . '/'],
      ['https://' . $source_no_scheme,       'https://' . $target_no_scheme],
      ['http://'  . $source_no_scheme . '/', 'http://'  . $target_no_scheme . '/'],
      ['http://'  . $source_no_scheme,       'http://'  . $target_no_scheme],
      ['//'       . $source_no_scheme . '/', '//'       . $target_no_scheme . '/'],
      ['//'       . $source_no_scheme,       '//'       . $target_no_scheme],
      // Escaped-slash variants for block-editor JSON in post_content.
      ['https:\/\/' . $source_no_scheme . '\/', 'https:\/\/' . $target_no_scheme . '\/'],
      ['https:\/\/' . $source_no_scheme,         'https:\/\/' . $target_no_scheme],
      ['http:\/\/'  . $source_no_scheme . '\/', 'http:\/\/'  . $target_no_scheme . '\/'],
      ['http:\/\/'  . $source_no_scheme,         'http:\/\/'  . $target_no_scheme],
    ];

    $search  = [];
    $replace = [];
    $seen    = [];
    foreach ($candidates as [$s, $r]) {
      if ($s === '' || $s === $r || isset($seen[$s])) {
        continue;
      }
      $seen[$s]  = true;
      $search[]  = $s;
      $replace[] = $r;
    }

    return [$search, $replace];
  }

  private static function normalize_url($url): string {
    $url = trim((string) $url);
    $url = untrailingslashit($url);
    return esc_url_raw($url);
  }

  private static function validate_restore_url(string $url, string $label, bool $allow_empty = false): string {
    $url = self::normalize_url($url);
    if ($url === '' && $allow_empty) {
      return '';
    }

    $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
    $host = (string) wp_parse_url($url, PHP_URL_HOST);
    if ($url === '' || !in_array($scheme, ['http', 'https'], true) || $host === '') {
      throw new RuntimeException(sprintf(
        /* translators: %s: URL field label (e.g. Source URL or Target URL) */
        __('%s must be a valid http or https URL.', 'restorepilot-backup-migration'),
        $label
      ));
    }

    return $url;
  }

  private static function normalize_server_path(string $path): string {
    $path = trim(str_replace("\0", '', $path));
    if ($path === '') {
      return '';
    }

    if (!preg_match('#^([a-z]:)?[/\\\\]#i', $path)) {
      $path = trailingslashit(ABSPATH) . ltrim($path, '/\\');
    }

    $real = realpath($path);
    return $real === false ? $path : $real;
  }
}
