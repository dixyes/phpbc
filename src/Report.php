<?php

declare(strict_types=1);

namespace PHPbc;

class Report{
    const JSON = "json";
    const MARKDOWN = "markdown";
    
    private string $type;
    private array $result;
    private array $config;
    public function __construct(array $result, mixed $outputSpec){
        if(is_string($outputSpec)){
            $lower = strtolower($outputSpec);
            switch (true){
                case str_ends_with($lower, ".json"):
                    $type = self::JSON;
                    break;
                case str_ends_with($lower, ".md"):
                    $type = self::MARKDOWN;
                    break;
                default:
                    throw new \RuntimeException("cannot determine $outputSpec file type");
            }
            $config = [
                "type" => $type,
                "name" => $outputSpec,
            ];
        }else if(is_array($outputSpec) && isset($outputSpec["type"])){
            $type = $outputSpec["type"];
            $config = $outputSpec;
        }else {
            throw new \RuntimeException("strange output spec");
        }
        $this->type = $type;
        $this->result = $result; 
        $this->config = $config;
    }
    public function generateStr():string {
        switch($this->type){
            case self::JSON:
                $options = JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
                if ($this->config["pretty"] ?? false){
                    $options |= JSON_PRETTY_PRINT;
                }
                return json_encode($this->result,  $options);
            case self::MARKDOWN:
                $title = $this->config["title"] ?? "PHP behavior changes";
                $sames = $this->config["sames"] ?? false;
                $ret = "";
                $ret .= "# $title\n";
                // test environment
                $ret .= "\n## Test Environment\n";
                $ret .= "\nCommands execution results, this described test environments\n";
                foreach($this->result["env"] as $cmd => $o){
                    $trimO = trim($o);
                    $ret .="\n### $cmd\n\n";
                    $ret .="```plain\n$trimO\n```\n";
                }
                // summary parts
                $ret .= "\n## Summary\n";
                $ret .= "\nWe regard a comparation have same result reported by run-test.php and same PHP output as \"exactly same result\", and breaking-change tests over not skipped tests as \"real bc rate\"\n";
                $ret .= "\n| Tests have exactly same result | Tests ran | All tests found | Overall bc rate | Real bc rate |";
                $ret .= "\n| - | - | - | - | - |";
                $ret .= sprintf(
                    "\n| %d | %d | %d | %0.2f | %0.2f |",
                    $this->result["summary"]["same"],
                    $this->result["summary"]["tested"],
                    $this->result["summary"]["all"],
                    $this->result["summary"]["overall_rate"],
                    $this->result["summary"]["real_rate"],
                );
                $ret .= "\n";
                // diff parts
                $ret .= "\n## Behavior changes\n";
                foreach($this->result["diffs"] as $test => $diff){
                    $ret .= "\n### $test\n\n";
                    $results = preg_split("|:|", $diff["type"]);
                    if(count($results)<2){
                        $ret .= "Tests " . $diff["type"] . " in both, but outputs is different.\n\n";
                    }else{
                        $ret .= sprintf("Tests %s in control but %s in experiment\n\n", $results[0], $results[1]);
                    }
                    $ret .= "```patch\n" . $diff["diff"] . "\n```\n";
                }
                // same parts
                if($this->config["sames"] ?? false){
                    $ret .= "\n## Tests have no behavior change\n";
                    $ret .= "\nthese tests have same result and exactly same output.\n";
                    foreach($this->result["sames"] as $type => $tests){
                        $ret .= "\n### Tests $type\n\n";
                        $ret .= implode("\n", array_map(function($s){
                            return "- $s";
                        }, $tests));
                        $ret .= "\n";
                    }
                }
                return $ret;
            default:
                throw new \RuntimeException("not supported type " . $this->type);
        }
    }
    public function generate(){
        Log::i($this->type, "report wrote to", $this->config["name"]);
        file_put_contents($this->config["name"], $this->generateStr());
    }
}