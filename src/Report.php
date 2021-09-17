<?php

declare(strict_types=1);

namespace PHPbc;

class Report
{
    public const JSON = 'json';

    public const MARKDOWN = 'markdown';

    public const HTML = 'html';

    private string $type;

    private array $result;

    private array $config;

    public function __construct(array $result, mixed $outputSpec)
    {
        if (is_string($outputSpec)) {
            $lower = strtolower($outputSpec);
            switch (true) {
                case str_ends_with($lower, '.json'):
                    $type = self::JSON;
                    break;
                case str_ends_with($lower, '.md'):
                    $type = self::MARKDOWN;
                    break;
                case str_ends_with($lower, '.html') || str_ends_with($lower, '.htm'):
                    if (!class_exists('\\Parsedown', true)) {
                        throw new \RuntimeException('erusev/parsedown is not installed');
                    }
                    $type = self::HTML;
                    break;
                default:
                    throw new \RuntimeException("cannot determine {$outputSpec} file type");
            }
            $config = [
                'type' => $type,
                'name' => $outputSpec,
            ];
        } elseif (is_array($outputSpec) && isset($outputSpec['type'])) {
            $type = $outputSpec['type'];
            $config = $outputSpec;
        } else {
            throw new \RuntimeException('strange output spec');
        }
        $this->type = $type;
        $this->result = $result;
        $this->config = $config;
    }

    public function generateStr(?string $type = null): string
    {
        if (!$type) {
            $type = $this->type;
        }
        switch ($type) {
            case self::JSON:
                $options = JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
                if ($this->config['pretty'] ?? false) {
                    $options |= JSON_PRETTY_PRINT;
                }

                return json_encode($this->result, $options);
            case self::MARKDOWN:
                $title = $this->config['title'] ?? 'PHP behavior changes';
                $sames = $this->config['sames'] ?? false;
                $ret = '';
                $ret .= "# {$title}\n";
                // test environment
                $ret .= "\n## Test Environment\n";
                $ret .= "\nCommands execution results, this described test environments\n";
                foreach ($this->result['env'] as $cmd => $o) {
                    $trimO = trim($o);
                    $ret .= "\n### {$cmd}\n\n";
                    $ret .= "```plain\n{$trimO}\n```\n";
                }
                // summary parts
                $ret .= "\n## Summary\n";
                $ret .= "\nWe regard a comparation have same result reported by run-test.php and same PHP output as \"exactly same result\", and breaking-change tests over not skipped tests as \"real bc rate\"\n";
                $ret .= "\n| Tests have exactly same result | Tests ran | All tests found | Overall bc rate | Real bc rate |";
                $ret .= "\n| - | - | - | - | - |";
                $ret .= sprintf(
                    "\n| %d | %d | %d | %0.4f%% | %0.4f%% |",
                    $this->result['summary']['same'],
                    $this->result['summary']['tested'],
                    $this->result['summary']['all'],
                    $this->result['summary']['overall_rate'] * 100,
                    $this->result['summary']['real_rate'] * 100,
                );
                $ret .= "\n";
                // diff parts
                $ret .= "\n## Behavior changes\n";
                foreach ($this->result['diffs'] as $test => $diff) {
                    $ret .= "\n### {$test}\n\n";
                    $results = preg_split('|:|', $diff['type']);
                    if (isset($diff['reason'])) {
                        $ret .= sprintf("Test %s in experiment beacuse\n\n", isset($results[1]) ? $results[1] : $diff['type']);
                        $ret .= "```patch\n" . $diff['reason'] . "\n```\n\n";
                    }
                    if (count($results) < 2) {
                        $ret .= 'Test ' . $diff['type'] . " in both, but outputs is different.\n\n";
                    } else {
                        $ret .= sprintf("Test %s in control but %s in experiment\n\n", $results[0], $results[1]);
                    }
                    if (isset($diff['diff'])) {
                        $ret .= "```patch\n" . $diff['diff'] . "\n```\n";
                    }
                }
                // same parts
                if ($this->config['sames'] ?? false) {
                    $ret .= "\n## Tests have no behavior change\n";
                    $ret .= "\nthese tests have same result and exactly same output.\n";
                    foreach ($this->result['sames'] as $type => $tests) {
                        $ret .= "\n### Tests {$type}\n\n";
                        $ret .= implode("\n", array_map(function ($s) {
                            return "- {$s}";
                        }, $tests));
                        $ret .= "\n";
                    }
                }

                return $ret;
            case self::HTML:
                if (!class_exists('\\Parsedown', true)) {
                    throw new \RuntimeException('erusev/parsedown is not installed');
                }
                $parsedown = new \Parsedown();
                $str = $this->generateStr(self::MARKDOWN);

                return $parsedown->text($str);
            default:
                throw new \RuntimeException('not supported type ' . $this->type);
        }
    }

    public function generate()
    {
        Log::i($this->type, 'report wrote to', $this->config['name']);
        file_put_contents($this->config['name'], $this->generateStr());
    }
}
