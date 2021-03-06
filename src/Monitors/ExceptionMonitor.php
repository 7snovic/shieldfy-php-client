<?php
namespace Shieldfy\Monitors;

use Shieldfy\Jury\Judge;

class ExceptionMonitor extends MonitorBase
{
    use Judge;

    protected $name = 'exceptions';
    /**
     * run the monitor
     * Monitor for expolits that generates errors
     * ex: LFI , RCE [eval , serialize] , SSRF
     * Exceptions to Monitor
     * Warning: require(xxx):  failed to open stream: No such file or directory ==> exception message
     * syntax error .... eval()'d code ==> exception file
     * unserialize(): Error at offset [0-9]+ of [0-9] bytes //note: serialize fuzzing may not generate errors
     */
    public function run()
    {
        $exceptions = $this->collectors['exceptions'];
        $exceptions->listen(function ($exception) {
            $this->analyze($exception);
        });
    }

    public function analyze($exception)
    {
        $this->issue('exceptions');
        if (!$this->isInScope($exception)) {
            return;
        }
        //in scope lets analyze it
        $request = $this->collectors['request'];
        $info = $request->getInfo();
        $params = array_merge($info['get'], $info['post'], $info['cookies']);

        $score = $requestScore = $request->getScore();
        $infection = [];
        foreach ($params as $key => $value) {
            $result = $this->sentence($value, 'REQUEST');
            if ($result['score']) {
                $score += $result['score'];
                $infection[$key] =  $result['ruleIds'];
            }
        }
        $code = $this->collectors['code']->collectFromFile($exception->getFile(), $exception->getLine());
        if ($score > $requestScore) {
            $this->handle([
                'score'=>$score,
                'infection'=>$infection
            ], $code);
        }
    }

    protected function isInScope($exception)
    {
        $message = $exception->getMessage();
        $res = $this->sentence($message, 'EXCEPTION:MSG');
        if ($res['score']) {
            return true;
        }

        $file = $exception->getFile();
        $res = $this->sentence($file, 'EXCEPTION:FILE');
        if ($res['score']) {
            return true;
        }

        return false;
    }
}
