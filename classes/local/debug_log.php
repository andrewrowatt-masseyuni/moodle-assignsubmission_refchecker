<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace assignsubmission_refchecker\local;

use core_text;

/**
 * The optional activity log: everything the checker did, and why.
 *
 * Checking happens in cron, across several tasks, against services that fail in ways nobody can
 * reproduce on demand. When a submission comes back with an answer a marker disputes, the only
 * honest way to explain it is to show which databases were asked, what they said, and where the
 * run gave up. mtrace() output goes to the cron log, which on a busy site is unusable for this and
 * on many sites is not readable by the person asking the question.
 *
 * Off by default, and cheap when off: {@see self::log()} does one cached config read and returns.
 *
 * The log records the text of student references, so it is personal data. That is the point — an
 * extraction fault is invisible without it — but it is why the setting warns, why retention is
 * capped at {@see self::MAX_RETENTION_HOURS} hours, and why old files are removed whether or not
 * logging is still switched on.
 *
 * @package    assignsubmission_refchecker
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class debug_log {
    /** @var string Directory under dataroot holding the log files. */
    public const DIRECTORY = 'assignsubmission_refchecker';

    /** @var string Prefix every log file carries. Also what marks a file as ours to delete. */
    public const PREFIX = 'refchecker-';

    /** @var int Hours of log kept when the setting has never been touched. */
    public const DEFAULT_RETENTION_HOURS = 24;

    /** @var int The longest retention an administrator may choose. */
    public const MAX_RETENTION_HOURS = 72;

    /**
     * Longest single value written to the log, in characters.
     *
     * Generous, because an extraction fault that produces one enormous "reference" is exactly the
     * thing somebody turns this on to see. Capped all the same so a runaway cannot fill dataroot.
     *
     * @var int
     */
    protected const MAX_VALUE_LENGTH = 1000;

    /** @var bool|null Cached answer for this request. */
    protected static ?bool $enabled = null;

    /** @var string|null The file this request last wrote to, used to spot an hour rolling over. */
    protected static ?string $lastfile = null;

    /**
     * Whether the administrator has switched activity logging on.
     *
     * Cached for the request: this is consulted on every request to every database, and a config
     * read per lookup would be a real cost for a feature that is off almost all of the time.
     *
     * @return bool
     */
    public static function enabled(): bool {
        if (self::$enabled === null) {
            self::$enabled = !empty(get_config('assignsubmission_refchecker', 'debuglog'));
        }

        return self::$enabled;
    }

    /**
     * Record one thing that happened.
     *
     * @param string $event A dotted event name, e.g. "http.response". Kept short and greppable.
     * @param array $context Values to record alongside it.
     * @return void
     */
    public static function log(string $event, array $context = []): void {
        if (!self::enabled()) {
            return;
        }

        $path = self::current_file();

        // A file that does not exist yet means the hour has rolled over, which is the natural
        // moment to drop what has expired. Doing it here rather than only on cron keeps the
        // shortest retention settings honest; the scheduled task is a backstop for when logging
        // has been switched off and the last files would otherwise sit there.
        if (self::$lastfile !== $path && !file_exists($path)) {
            self::purge();
        }
        self::$lastfile = $path;

        // LOCK_EX because several cron workers check different submissions at the same time.
        @file_put_contents($path, self::format($event, $context), FILE_APPEND | LOCK_EX);
    }

    /**
     * Build one log line.
     *
     * @param string $event
     * @param array $context
     * @return string
     */
    protected static function format(string $event, array $context): string {
        // Server time, deliberately, so a line's timestamp and the hour in its filename agree.
        // userdate() would render in the timezone of whoever happened to trigger the request.
        $parts = [date('Y-m-d H:i:s'), $event];

        foreach ($context as $key => $value) {
            $parts[] = $key . '=' . self::value($value);
        }

        return implode(' ', $parts) . "\n";
    }

    /**
     * Render one context value as a single readable token.
     *
     * Everything ends up on one line, because a log somebody greps is worth more than a log that
     * preserves a reference's original line breaks.
     *
     * @param mixed $value
     * @return string
     */
    protected static function value($value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if (is_array($value)) {
            $value = implode(',', array_map(static fn($item) => (string) $item, $value));
        }

        $value = (string) $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value));

        if (core_text::strlen($value) > self::MAX_VALUE_LENGTH) {
            $value = core_text::substr($value, 0, self::MAX_VALUE_LENGTH) . '…[truncated]';
        }

        // Quote anything that would otherwise run into the next key=value pair.
        if ($value === '' || preg_match('/[\s"=]/', $value)) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }

        return $value;
    }

    /**
     * The file the current hour's entries belong in.
     *
     * @return string Full path.
     */
    protected static function current_file(): string {
        return self::directory() . '/' . self::PREFIX . date('Ymd-H') . '.log';
    }

    /**
     * The log directory, created if it is not there yet.
     *
     * @return string Full path.
     */
    public static function directory(): string {
        // Under dataroot rather than in the file storage API: this is append-heavy, and a
        // stored_file would have to be rewritten whole on every line.
        return make_upload_directory(self::DIRECTORY);
    }

    /**
     * How many hours of log to keep.
     *
     * @return int
     */
    public static function retention_hours(): int {
        $configured = (int) get_config('assignsubmission_refchecker', 'debuglogretention');
        $options = self::retention_options();

        // A value that is not one of the offered ones, including the 0 of a fresh install, falls
        // back to the default rather than being honoured as "keep nothing" or "keep forever".
        return isset($options[$configured]) ? $configured : self::DEFAULT_RETENTION_HOURS;
    }

    /**
     * The retention choices, longest first, keyed by hours.
     *
     * @return array<int, string>
     */
    public static function retention_options(): array {
        $options = [];
        foreach ([72, 48, 24, 12, 8, 4, 2, 1] as $hours) {
            $options[$hours] = $hours === 1
                ? get_string('debuglogretention_hour', 'assignsubmission_refchecker')
                : get_string('debuglogretention_hours', 'assignsubmission_refchecker', $hours);
        }

        return $options;
    }

    /**
     * The log files that exist, newest first.
     *
     * @return array<int, array{name: string, path: string, size: int, hour: int}>
     */
    public static function files(): array {
        $files = [];

        foreach (self::filenames() as $name) {
            $hour = self::hour_of($name);
            if ($hour === null) {
                continue;
            }
            $path = self::directory() . '/' . $name;
            $files[] = [
                'name' => $name,
                'path' => $path,
                'size' => (int) filesize($path),
                'hour' => $hour,
            ];
        }

        usort($files, static fn($a, $b) => $b['hour'] <=> $a['hour']);

        return $files;
    }

    /**
     * Resolve a caller-supplied filename to a path inside the log directory.
     *
     * The only way a filename reaches this class from outside is a request parameter, so it is
     * matched against the pattern we write rather than merely cleaned: nothing that is not a log
     * file this class created can be named, whatever the caller sends.
     *
     * @param string $name
     * @return string|null Null when the name is not one of ours, or the file is not there.
     */
    public static function file_path(string $name): ?string {
        if (self::hour_of($name) === null) {
            return null;
        }

        $path = self::directory() . '/' . $name;

        return is_file($path) ? $path : null;
    }

    /**
     * Delete log files older than the retention setting.
     *
     * Runs whether or not logging is currently enabled: switching it off must not strand the last
     * few hours of student reference text in dataroot indefinitely.
     *
     * @return int How many files were deleted.
     */
    public static function purge(): int {
        $cutoff = time() - (self::retention_hours() * HOURSECS);
        $deleted = 0;

        foreach (self::filenames() as $name) {
            $hour = self::hour_of($name);
            if ($hour === null || $hour >= $cutoff) {
                continue;
            }
            if (@unlink(self::directory() . '/' . $name)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Delete every log file, whatever its age.
     *
     * @return int How many files were deleted.
     */
    public static function purge_all(): int {
        $deleted = 0;

        foreach (self::filenames() as $name) {
            if (self::hour_of($name) !== null && @unlink(self::directory() . '/' . $name)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * The names of the files in the log directory.
     *
     * @return string[]
     */
    protected static function filenames(): array {
        $names = scandir(self::directory());

        return $names === false ? [] : array_diff($names, ['.', '..']);
    }

    /**
     * The hour a log file covers, taken from its name.
     *
     * Read from the name rather than from the modification time so that a file copied about, or
     * touched by a backup, is still judged on the hour it actually describes. A name that is not
     * ours returns null, which is what stops purge() deleting anything it did not write.
     *
     * @param string $name
     * @return int|null Unix time of the start of the hour, or null when this is not a log file.
     */
    protected static function hour_of(string $name): ?int {
        $pattern = '/^' . preg_quote(self::PREFIX, '/') . '(\d{4})(\d{2})(\d{2})-(\d{2})\.log$/';
        if (!preg_match($pattern, $name, $matches)) {
            return null;
        }

        return (int) mktime((int) $matches[4], 0, 0, (int) $matches[2], (int) $matches[3], (int) $matches[1]);
    }

    /**
     * Forget the cached enabled flag. For tests, which change the setting mid-run.
     *
     * @return void
     */
    public static function reset_caches(): void {
        self::$enabled = null;
        self::$lastfile = null;
    }
}
