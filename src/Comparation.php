<?php

declare(strict_types=1);

namespace PHPbc;

class Comparation
{
    private Task $ctrl;

    private Task $expr;

    public function __construct(Task $ctrl, Task $expr)
    {
        $this->ctrl = $ctrl;
        $this->expr = $expr;
    }

    private array $diffs = [];

    private array $sames = [];

    private function compare(string $test)
    {
        $testName = str_replace(DIRECTORY_SEPARATOR, '/', $test);
        $ctrl_result = $this->ctrl->results[$test];
        if (!isset($this->expr->results[$test])) {
            Log::w('no such tests in expriment', test: $test);
            $this->diffs[$testName] = [
                'type' => "{$ctrl_result}:UNKNOWN",
            ];

            return;
        }

        $expr_result = $this->expr->results[$test];
        $type = null;
        $diff = null;
        $reason = null;
        $outName = preg_replace('|\\.phpt$|', '.out', $test);
        $ctrl_outName = Util::path_join($this->ctrl->getWorkDir(), $outName);
        $expr_outName = Util::path_join($this->expr->getWorkDir(), $outName);
        if ($expr_result === $ctrl_result) {
            $type = $ctrl_result;
            if ($ctrl_result === 'FAILED' ||
                $ctrl_result === 'XFAILED' ||
                $ctrl_result === 'LEAKED') {
                // compare outputs when both failed

                if (is_file($ctrl_outName) || is_file($expr_outName)) {
                    // at least one side outputs is present
                    $cout = is_file($ctrl_outName) ? file_get_contents($ctrl_outName) : 'Control outputs is not present';
                    $eout = is_file($expr_outName) ? file_get_contents($expr_outName) : 'Experiment outputs is not present';
                    $eout = str_replace($this->expr->getWorkDir(), $this->ctrl->getWorkDir(), $eout);
                    //printf("compare %s with %s\n", $cout, $eout);
                    $diff = Util::generate_diff($cout, null, $eout);
                    //printf("result %s\n", $diff);
                    // read failed reason
                    $diffName = preg_replace('|\\.phpt$|', '.diff', $test);
                    $ediffName = Util::path_join($this->expr->getWorkDir(), $diffName);
                    if (is_file($ediffName)) {
                        $reason = file_get_contents($ediffName);
                        if (strlen($reason) > 4096) {
                            $reason = implode("\n", [str_split($reason, 4096)[0], '...']);
                        }
                    }
                }
            }
        } else {
            $diff = 'not generated';
            $type = "{$ctrl_result}:{$expr_result}";
            if (is_file($ctrl_outName) && is_file($expr_outName)) {
                $diff = Util::generate_diff(file_get_contents($ctrl_outName), null, file_get_contents($expr_outName));
            } else {
                $diffName = Util::path_join($this->expr->getWorkDir(), preg_replace('|\\.phpt$|', '.diff', $test));
                if ($ctrl_result === 'PASSED' && is_file($diffName)) {
                    $diff = file_get_contents($diffName);
                }
            }
        }
        if ($diff) {
            $this->diffs[$testName] = [
                'type' => $type,
                'diff' => $diff,
            ];
            if ($reason) {
                $this->diffs[$testName]['reason'] = $reason;
            }
        } else {
            if (!isset($this->sames[$type])) {
                $this->sames[$type] = [];
            }
            $this->sames[$type][] = $testName;
        }
    }

    public function report(): array
    {
        foreach ($this->ctrl->results as $ctest => $_) {
            $this->compare($ctest);
        }

        return [
            'diffs' => $this->diffs,
            'sames' => $this->sames,
        ];
    }
}
