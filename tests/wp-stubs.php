<?php
declare(strict_types=1);

// Minimal WordPress stand-ins for unit tests that touch code paths calling
// WordPress functions (options, hooks, HTTP). Every function is only defined
// if WordPress itself is not loaded. State lives in Sonoquill\Tests\Wp and is
// reset with Wp::reset() in setUp().

namespace Sonoquill\Tests {

    final class Wp
    {
        /** @var array<string,mixed> */
        public static array $options = [];

        /** @var array<string,array<int,list<callable>>> */
        public static array $filters = [];

        /** @var list<array{0:string,1:list<mixed>}> Every do_action() call, in order. */
        public static array $actions = [];

        /** @var list<array{0:string,1:array<string,mixed>}> Requests made through wp_remote_*(). */
        public static array $requests = [];

        /** @var callable|null fn(string $url, array $args): array|\WP_Error */
        public static $http = null;

        /** @var list<array{0:mixed,1:string,2:string}> wp_mail() calls. */
        public static array $mails = [];

        /** @var list<array{0:string,1:array<int,mixed>}> Jobs passed to as_schedule_single_action(). */
        public static array $scheduled = [];

        /** Base directory returned by wp_upload_dir(). */
        public static string $uploads = '';

        public static function reset(): void
        {
            self::$options = [];
            self::$filters = [];
            self::$actions = [];
            self::$requests = [];
            self::$http = null;
            self::$mails = [];
            self::$scheduled = [];
            self::$uploads = sys_get_temp_dir() . '/sonoquill-uploads-' . getmypid();
            $GLOBALS['wpdb'] = new FakeWpdb();
        }

        /**
         * @return list<list<mixed>> The arguments of every call of one action.
         */
        public static function fired(string $hook): array
        {
            $out = [];
            foreach (self::$actions as [$name, $args]) {
                if ($name === $hook) {
                    $out[] = $args;
                }
            }

            return $out;
        }
    }

    /**
     * In-memory stand-in for $wpdb, enough for EpisodeRepository and
     * SegmentRepository: insert, update, get_row by id, delete.
     */
    final class FakeWpdb
    {
        public string $prefix = 'wp_';
        public string $options = 'wp_options';
        public int $insert_id = 0;

        /** @var array<string,array<int,array<string,mixed>>> */
        public array $tables = [];

        public function insert(string $table, array $data): int
        {
            $id = (int) ($data['id'] ?? (max(array_merge([0], array_keys($this->tables[$table] ?? []))) + 1));
            $data['id'] = $id;
            $this->tables[$table][$id] = $data;
            $this->insert_id = $id;

            return 1;
        }

        public function update(string $table, array $data, array $where): int
        {
            $id = (int) ($where['id'] ?? 0);
            if (!isset($this->tables[$table][$id])) {
                return 0;
            }
            $this->tables[$table][$id] = array_merge($this->tables[$table][$id], $data);

            return 1;
        }

        public function delete(string $table, array $where): int
        {
            if (isset($where['id'])) {
                unset($this->tables[$table][(int) $where['id']]);
            }

            return 1;
        }

        public function prepare(string $query, ...$args): string
        {
            $args = isset($args[0]) && is_array($args[0]) ? $args[0] : $args;
            $query = str_replace(['%d', '%s', '%f'], ['%d', "'%s'", '%F'], $query);

            return vsprintf($query, $args);
        }

        public function get_row(string $query, $output = null): ?array
        {
            if (preg_match('/FROM\s+(\S+)\s+WHERE\s+id\s*=\s*(\d+)/i', $query, $m) === 1) {
                return $this->tables[$m[1]][(int) $m[2]] ?? null;
            }

            return null;
        }

        public function get_results(string $query, $output = null): array
        {
            if (preg_match('/FROM\s+(\S+)\s+WHERE\s+episode_id\s*=\s*(\d+)/i', $query, $m) === 1) {
                $rows = array_values(array_filter(
                    $this->tables[$m[1]] ?? [],
                    static fn (array $r): bool => (int) ($r['episode_id'] ?? 0) === (int) $m[2]
                ));
                usort($rows, static fn (array $a, array $b): int => ((int) ($a['idx'] ?? 0)) <=> ((int) ($b['idx'] ?? 0)));

                return $rows;
            }

            return [];
        }

        public function get_var(string $query)
        {
            return null;
        }

        public function get_col(string $query): array
        {
            return [];
        }

        public function query(string $query): int
        {
            return 1;
        }

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function get_charset_collate(): string
        {
            return '';
        }
    }
}

namespace {

    use Sonoquill\Tests\Wp;

    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    if (!defined('HOUR_IN_SECONDS')) {
        define('HOUR_IN_SECONDS', 3600);
    }
    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }
    if (!defined('WEEK_IN_SECONDS')) {
        define('WEEK_IN_SECONDS', 604800);
    }
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/sonoquill-wp/');
    }
    if (!defined('AASPF_PLUGIN_DIR')) {
        define('AASPF_PLUGIN_DIR', dirname(__DIR__) . '/');
    }

    if (!class_exists('WpDieException')) {
        /** Thrown by the wp_die() stub so that tests can inspect the page. */
        class WpDieException extends \RuntimeException
        {
            public function __construct(public string $html, public int $status)
            {
                parent::__construct(strip_tags($html), $status);
            }
        }
    }

    if (!function_exists('wp_die')) {
        function wp_die($message = '', $title = '', $args = []): void
        {
            throw new WpDieException((string) $message, (int) ($args['response'] ?? 500));
        }
    }
    if (!function_exists('wp_unslash')) {
        function wp_unslash($value)
        {
            return $value;
        }
    }
    if (!function_exists('absint')) {
        function absint($value): int
        {
            return abs((int) $value);
        }
    }
    if (!function_exists('sanitize_key')) {
        function sanitize_key(string $key): string
        {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
        }
    }
    if (!function_exists('sanitize_text_field')) {
        function sanitize_text_field(string $text): string
        {
            return trim(strip_tags($text));
        }
    }

    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            public function __construct(private string $code = '', private string $message = '')
            {
            }

            public function get_error_message(): string
            {
                return $this->message;
            }
        }
    }

    if (!function_exists('add_filter')) {
        function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
        {
            Wp::$filters[$hook][$priority][] = $callback;

            return true;
        }
    }
    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
        {
            return add_filter($hook, $callback, $priority, $args);
        }
    }
    if (!function_exists('has_filter')) {
        function has_filter(string $hook, $callback = false)
        {
            return !empty(Wp::$filters[$hook]);
        }
    }
    if (!function_exists('remove_all_filters')) {
        function remove_all_filters(string $hook): bool
        {
            unset(Wp::$filters[$hook]);

            return true;
        }
    }
    if (!function_exists('apply_filters')) {
        function apply_filters(string $hook, $value, ...$args)
        {
            $byPriority = Wp::$filters[$hook] ?? [];
            ksort($byPriority);
            foreach ($byPriority as $callbacks) {
                foreach ($callbacks as $callback) {
                    $value = $callback($value, ...$args);
                }
            }

            return $value;
        }
    }
    if (!function_exists('do_action')) {
        function do_action(string $hook, ...$args): void
        {
            Wp::$actions[] = [$hook, $args];
            $byPriority = Wp::$filters[$hook] ?? [];
            ksort($byPriority);
            foreach ($byPriority as $callbacks) {
                foreach ($callbacks as $callback) {
                    $callback(...$args);
                }
            }
        }
    }
    if (!function_exists('get_option')) {
        function get_option(string $name, $default = false)
        {
            return array_key_exists($name, Wp::$options) ? Wp::$options[$name] : $default;
        }
    }
    if (!function_exists('update_option')) {
        function update_option(string $name, $value, $autoload = null): bool
        {
            Wp::$options[$name] = $value;

            return true;
        }
    }
    if (!function_exists('add_option')) {
        function add_option(string $name, $value = '', $deprecated = '', $autoload = null): bool
        {
            if (!array_key_exists($name, Wp::$options)) {
                Wp::$options[$name] = $value;
            }

            return true;
        }
    }
    if (!function_exists('delete_option')) {
        function delete_option(string $name): bool
        {
            unset(Wp::$options[$name]);

            return true;
        }
    }
    if (!function_exists('get_transient')) {
        function get_transient(string $name)
        {
            return false;
        }
    }
    if (!function_exists('set_transient')) {
        function set_transient(string $name, $value, int $expiration = 0): bool
        {
            return true;
        }
    }
    if (!function_exists('delete_transient')) {
        function delete_transient(string $name): bool
        {
            return true;
        }
    }
    if (!function_exists('untrailingslashit')) {
        function untrailingslashit(string $value): string
        {
            return rtrim($value, '/\\');
        }
    }
    if (!function_exists('trailingslashit')) {
        function trailingslashit(string $value): string
        {
            return untrailingslashit($value) . '/';
        }
    }
    if (!function_exists('wp_json_encode')) {
        function wp_json_encode($data, int $flags = 0)
        {
            return json_encode($data, $flags);
        }
    }
    if (!function_exists('current_time')) {
        function current_time(string $type, bool $gmt = false)
        {
            return $type === 'timestamp' ? time() : gmdate('Y-m-d H:i:s');
        }
    }
    if (!function_exists('wp_salt')) {
        function wp_salt(string $scheme = 'auth'): string
        {
            return 'test-salt-' . $scheme;
        }
    }
    if (!function_exists('home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://example.test' . $path;
        }
    }
    if (!function_exists('admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://example.test/wp-admin/' . ltrim($path, '/');
        }
    }
    if (!function_exists('add_query_arg')) {
        function add_query_arg(array $args, string $url): string
        {
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
        }
    }
    if (!function_exists('esc_html')) {
        function esc_html(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES);
        }
    }
    if (!function_exists('esc_attr')) {
        function esc_attr(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES);
        }
    }
    if (!function_exists('esc_url')) {
        function esc_url(string $url): string
        {
            return $url;
        }
    }
    if (!function_exists('number_format_i18n')) {
        function number_format_i18n($number, int $decimals = 0): string
        {
            return number_format((float) $number, $decimals);
        }
    }
    if (!function_exists('size_format')) {
        function size_format($bytes, int $decimals = 0): string
        {
            return $bytes . ' B';
        }
    }
    if (!function_exists('wp_parse_url')) {
        function wp_parse_url(string $url, int $component = -1)
        {
            return parse_url($url, $component);
        }
    }
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing): bool
        {
            return $thing instanceof WP_Error;
        }
    }
    if (!function_exists('wp_remote_request')) {
        function wp_remote_request(string $url, array $args = [])
        {
            Wp::$requests[] = [$url, $args];
            $handler = Wp::$http;

            return $handler !== null ? $handler($url, $args) : new WP_Error('http', 'no network in tests');
        }
    }
    if (!function_exists('wp_remote_get')) {
        function wp_remote_get(string $url, array $args = [])
        {
            return wp_remote_request($url, ['method' => 'GET'] + $args);
        }
    }
    if (!function_exists('wp_remote_head')) {
        function wp_remote_head(string $url, array $args = [])
        {
            return wp_remote_request($url, ['method' => 'HEAD'] + $args);
        }
    }
    if (!function_exists('wp_remote_post')) {
        function wp_remote_post(string $url, array $args = [])
        {
            return wp_remote_request($url, ['method' => 'POST'] + $args);
        }
    }
    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response)
        {
            return is_array($response) ? (int) ($response['response']['code'] ?? 0) : '';
        }
    }
    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body($response): string
        {
            return is_array($response) ? (string) ($response['body'] ?? '') : '';
        }
    }
    if (!function_exists('wp_mail')) {
        function wp_mail($to, string $subject, string $message, $headers = ''): bool
        {
            Wp::$mails[] = [$to, $subject, $message];

            return true;
        }
    }
    if (!function_exists('is_email')) {
        function is_email(string $email)
        {
            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : false;
        }
    }
    if (!function_exists('as_schedule_single_action')) {
        function as_schedule_single_action(int $timestamp, string $hook, array $args = [], string $group = ''): int
        {
            Wp::$scheduled[] = [$hook, $args];

            return count(Wp::$scheduled);
        }
    }
    if (!function_exists('wp_upload_dir')) {
        function wp_upload_dir($time = null, bool $create = true): array
        {
            return ['basedir' => Wp::$uploads, 'baseurl' => 'https://example.test/wp-content/uploads'];
        }
    }
    if (!function_exists('wp_mkdir_p')) {
        function wp_mkdir_p(string $dir): bool
        {
            return is_dir($dir) || @mkdir($dir, 0777, true);
        }
    }
    if (!function_exists('wp_generate_password')) {
        function wp_generate_password(int $length = 12, bool $special = true): string
        {
            return substr(bin2hex(random_bytes($length)), 0, $length);
        }
    }
    if (!function_exists('wp_delete_file')) {
        function wp_delete_file(string $file): void
        {
            @unlink($file);
        }
    }
}
