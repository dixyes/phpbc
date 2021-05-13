<?php 

declare(strict_types=1);

namespace PHPbc;

class Util{
    public static function enable_error_handler(){
        set_error_handler(function ($severity, $message, $file, $line) {
            if (!(error_reporting() & $severity)) {
                // This error code is not included in error_reporting
                return;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }
    public static function path_join($parent, $child){
        if(str_ends_with($parent, "\\") || str_ends_with($parent, "/")){
            return $parent . $child;
        }
        return $parent . DIRECTORY_SEPARATOR . $child;
    }
    private static function test_dir_cmp(string $a, string $b){
        $aparts = preg_split('/[\\/\\\\]/', $a);
        $bparts = preg_split('/[\\/\\\\]/', $b);
        if(count($aparts) !== count($bparts)){
            return count($aparts) - count($bparts);
        }
        foreach($aparts as $ak => $apart){
            if(!isset($bparts[$ak])){
                return count($aparts) - count($bparts);
            }
            $scmp = strcmp($apart, $bparts[$ak]);
            if(0!=$scmp){
                return $scmp;
            }
        }
    }
    public static function walk_tests(string $path = ".", array $filter = [], array $skip = []): array{
        $filterRes = array_map(function($v){
            //printf('skip pattern "%s"' . \PHP_EOL, str_replace("/", preg_quote(DIRECTORY_SEPARATOR), "|$v|"));
            return str_replace("/", preg_quote(DIRECTORY_SEPARATOR), "|^$v$|");
        }, $filter);
        $useFilter = count($filter) > 0;
        $skipRes = array_map(function($v){
            //printf('skip pattern "%s"' . \PHP_EOL, str_replace("/", preg_quote(DIRECTORY_SEPARATOR), "|$v|"));
            return str_replace("/", preg_quote(DIRECTORY_SEPARATOR), "|^$v$|");
        }, $skip);
        $tests = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
        $it->rewind();
        $sum = 0;
        while($it->valid()){
            if(!$it->isDot()){
                $dir = $it->getSubPath();
                $fn = $it->getSubPathName();
                if(str_ends_with($fn, ".phpt")){
                    $skipThis = false;
                    if($useFilter){
                        $skipThis = true;
                        foreach($filterRes as $re){
                            if(preg_match($re, $fn)){
                                //printf("skip %s\n", $fn);
                                $skipThis = false;
                                break;
                            }
                        }
                    }
                    if(!$skipThis){
                        foreach($skipRes as $re){
                            if(preg_match($re, $fn)){
                                //printf("skip %s\n", $fn);
                                $skipThis = true;
                                break;
                            }
                        }
                    }
                    if(!$skipThis){
                        if(!isset($tests[$dir])){
                            $tests[$dir] = [];
                        }
                        $tests[$dir][] = $fn;
                        $sum++;
                    }
                }
            }
            $it->next();
        }
        uksort($tests, [Util::class, "test_dir_cmp"]);
        //var_dump(array_keys($tests));
        Log::i("found", $sum, "tests");
        return $tests;
    }
    // stolen from run-tests.php
    static function comp_line(string $l1, string $l2, bool $is_reg)
    {
        if ($is_reg) {
            return preg_match('/^' . $l1 . '$/s', $l2);
        } else {
            return !strcmp($l1, $l2);
        }
    }

    static function count_array_diff(
        array $ar1,
        array $ar2,
        bool $is_reg,
        array $w,
        int $idx1,
        int $idx2,
        int $cnt1,
        int $cnt2,
        int $steps
    ): int {
        $equal = 0;

        while ($idx1 < $cnt1 && $idx2 < $cnt2 && self::comp_line($ar1[$idx1], $ar2[$idx2], $is_reg)) {
            $idx1++;
            $idx2++;
            $equal++;
            $steps--;
        }
        if (--$steps > 0) {
            $eq1 = 0;
            $st = (int)($steps / 2);

            for ($ofs1 = $idx1 + 1; $ofs1 < $cnt1 && $st-- > 0; $ofs1++) {
                $eq = @self::count_array_diff($ar1, $ar2, $is_reg, $w, $ofs1, $idx2, $cnt1, $cnt2, $st);

                if ($eq > $eq1) {
                    $eq1 = $eq;
                }
            }

            $eq2 = 0;
            $st = $steps;

            for ($ofs2 = $idx2 + 1; $ofs2 < $cnt2 && $st-- > 0; $ofs2++) {
                $eq = @self::count_array_diff($ar1, $ar2, $is_reg, $w, $idx1, $ofs2, $cnt1, $cnt2, $st);
                if ($eq > $eq2) {
                    $eq2 = $eq;
                }
            }

            if ($eq1 > $eq2) {
                $equal += $eq1;
            } elseif ($eq2 > 0) {
                $equal += $eq2;
            }
        }

        return $equal;
    }
    static private function generate_array_diff(array $ar1, array $ar2, bool $is_reg, array $w): array{
        global $context_line_count;
        $idx1 = 0;
        $cnt1 = @count($ar1);
        $idx2 = 0;
        $cnt2 = @count($ar2);
        $diff = [];
        $old1 = [];
        $old2 = [];
        $number_len = max(3, strlen((string)max($cnt1 + 1, $cnt2 + 1)));
        $line_number_spec = '%0' . $number_len . 'd';

        /** Mapping from $idx2 to $idx1, including indexes of idx2 that are identical to idx1 as well as entries that don't have matches */
        $mapping = [];

        while ($idx1 < $cnt1 && $idx2 < $cnt2) {
            $mapping[$idx2] = $idx1;
            if (self::comp_line($ar1[$idx1], $ar2[$idx2], $is_reg)) {
                $idx1++;
                $idx2++;
                continue;
            } else {
                $c1 = @self::count_array_diff($ar1, $ar2, $is_reg, $w, $idx1 + 1, $idx2, $cnt1, $cnt2, 10);
                $c2 = @self::count_array_diff($ar1, $ar2, $is_reg, $w, $idx1, $idx2 + 1, $cnt1, $cnt2, 10);

                if ($c1 > $c2) {
                    $old1[$idx1] = sprintf("{$line_number_spec}- ", $idx1 + 1) . $w[$idx1++];
                } elseif ($c2 > 0) {
                    $old2[$idx2] = sprintf("{$line_number_spec}+ ", $idx2 + 1) . $ar2[$idx2++];
                } else {
                    $old1[$idx1] = sprintf("{$line_number_spec}- ", $idx1 + 1) . $w[$idx1++];
                    $old2[$idx2] = sprintf("{$line_number_spec}+ ", $idx2 + 1) . $ar2[$idx2++];
                }
                $last_printed_context_line = $idx1;
            }
        }
        $mapping[$idx2] = $idx1;

        reset($old1);
        $k1 = key($old1);
        $l1 = -2;
        reset($old2);
        $k2 = key($old2);
        $l2 = -2;
        $old_k1 = -1;
        $add_context_lines = function (int $new_k1) use (&$old_k1, &$diff, $w, $context_line_count, $number_len) {
            if ($old_k1 >= $new_k1 || !$context_line_count) {
                return;
            }
            $end = $new_k1 - 1;
            $range_end = min($end, $old_k1 + $context_line_count);
            if ($old_k1 >= 0) {
                while ($old_k1 < $range_end) {
                    $diff[] = str_repeat(' ', $number_len + 2) . $w[$old_k1++];
                }
            }
            if ($end - $context_line_count > $old_k1) {
                $old_k1 = $end - $context_line_count;
                if ($old_k1 > 0) {
                    // Add a '--' to mark sections where the common areas were truncated
                    $diff[] = '--';
                }
            }
            $old_k1 = max($old_k1, 0);
            while ($old_k1 < $end) {
                $diff[] = str_repeat(' ', $number_len + 2) . $w[$old_k1++];
            }
            $old_k1 = $new_k1;
        };

        while ($k1 !== null || $k2 !== null) {
            if ($k1 == $l1 + 1 || $k2 === null) {
                $add_context_lines($k1);
                $l1 = $k1;
                $diff[] = current($old1);
                $old_k1 = $k1;
                $k1 = next($old1) ? key($old1) : null;
            } elseif ($k2 == $l2 + 1 || $k1 === null) {
                $add_context_lines($mapping[$k2]);
                $l2 = $k2;
                $diff[] = current($old2);
                $k2 = next($old2) ? key($old2) : null;
            } elseif ($k1 < $mapping[$k2]) {
                $add_context_lines($k1);
                $l1 = $k1;
                $diff[] = current($old1);
                $k1 = next($old1) ? key($old1) : null;
            } else {
                $add_context_lines($mapping[$k2]);
                $l2 = $k2;
                $diff[] = current($old2);
                $k2 = next($old2) ? key($old2) : null;
            }
        }

        while ($idx1 < $cnt1) {
            $add_context_lines($idx1 + 1);
            $diff[] = sprintf("{$line_number_spec}- ", $idx1 + 1) . $w[$idx1++];
        }

        while ($idx2 < $cnt2) {
            if (isset($mapping[$idx2])) {
                $add_context_lines($mapping[$idx2] + 1);
            }
            $diff[] = sprintf("{$line_number_spec}+ ", $idx2 + 1) . $ar2[$idx2++];
        }
        $add_context_lines(min($old_k1 + $context_line_count + 1, $cnt1 + 1));
        if ($context_line_count && $old_k1 < $cnt1 + 1) {
            // Add a '--' to mark sections where the common areas were truncated
            $diff[] = '--';
        }

        return $diff;
    }

    static public function generate_diff(string $wanted, ?string $wanted_re, string $output): string{
        $w = explode("\n", $wanted);
        $o = explode("\n", $output);
        $r = is_null($wanted_re) ? $w : explode("\n", $wanted_re);
        $diff = self::generate_array_diff($r, $o, !is_null($wanted_re), $w);

        return implode(PHP_EOL, $diff);
    }
}
