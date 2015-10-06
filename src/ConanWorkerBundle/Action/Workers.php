<?php

namespace TreeHouse\ConanWorkerBundle\Action;

use Fieg\Statistico\Reader;
use FM\ConanBundle\Action\CustomAction;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use TreeHouse\ConanStatisticoBundle\Exception\DirectResponseException;
use TreeHouse\WorkerBundle\QueueManager;

class Workers extends CustomAction
{
    protected $request;
    protected $tube;
    protected $template = 'TreeHouseConanWorkerBundle::workers.html.twig';

    /**
     * @inheritdoc
     */
    public function execute(Request $request)
    {
        $this->request = $request;
        $this->tube = $this->request->get('tube');

        if ($tube = $this->request->get('tube')) {
            $this->template = 'TreeHouseConanWorkerBundle::worker.html.twig';

            return array_merge($this->getActionData(), $this->getTubeData($this->request, $tube));
        } else if ($bucket = $this->request->get('bucket')) {
            $type = $this->request->get('type', 'counts');

            return $this->getBucketData($this->request, $bucket, $type);
        }

        return parent::execute($request);
    }


    /**
     * @param Request $request
     * @param $bucket
     *
     * @return array
     *
     * @throws DirectResponseException
     */
    public function getBucketData(Request $request, $bucket, $type)
    {
        /** @var Reader $reader */
        $reader = $this->getConfig()->get('statistico.reader');

        $granularity = 'minutes';
        $factor = 60;
        $from = new \DateTime('-30 minutes');
        $to = null;

        switch ($type) {
            case "counts":
                $counts = $reader->queryCounts($bucket, $granularity, $from, $to);
                $counts = $this->completeCountsData($counts, $factor, $from, $to);
                break;
            case "gauges":
                $counts = $reader->queryGauges($bucket, $granularity, $from, $to);
                $counts = $this->completeGaugesData($counts, $factor, $from, $to);
                break;
        }

        $retval = [
            'series' => [],
            'from' => $from->getTimestamp(),
            'to' => (new \DateTime())->getTimestamp(),
        ];

        foreach ($counts as $timestamp => $count) {
            $retval['series'][] = ['date' => (string) $timestamp, 'count' => $count];
        }

        throw new DirectResponseException(new JsonResponse($retval));
    }

    /**
     * @param Request $request
     * @param $tube
     *
     * @return array
     *
     * @throws DirectResponseException
     */
    public function getTubeData(Request $request, $tube)
    {
        $data = array(
            'tube' => $tube,
            'stats' => $this->getTubeStats($tube),
        );

        if ('1' === $request->get('json')) {
            /** @var Reader $reader */
            $reader = $this->getConfig()->get('statistico.reader');

            $granularity = 'minutes';
            $factor = 60;
            $from = new \DateTime('-30 minutes');
            $to = null;

            $counts = $reader->queryCounts('job.execute.' . $tube, $granularity, $from, $to);
            $counts = $this->completeCountsData($counts, $factor, $from, $to);

            $retval = [
                'series' => [],
                'from' => $from->getTimestamp(),
                'to' => (new \DateTime())->getTimestamp(),
            ];

            foreach ($counts as $timestamp => $count) {
                $retval['series'][] = ['date' => (string) $timestamp, 'count' => $count];
            }

            throw new DirectResponseException(new JsonResponse($retval));
        }

        return $data;
    }

    /**
     * @inheritdoc
     */
    public function getCustomData()
    {
        $data = array(
            'stats' => $this->getServerStats(),
            'tubes' => array(),
        );

        foreach ($this->getPheanstalk()->listTubes() as $tube) {
            if ($tube === 'default') {
                continue;
            }
            $data['tubes'][$tube] = $this->getTubeStats($tube);
        }

        ksort($data['tubes']);

        return $data;
    }

    /**
     * @return QueueManager
     */
    protected function getQueueManager()
    {
        return $this->getConfig()->get('tree_house.worker.queue_manager');
    }

    /**
     * @return \Pheanstalk\PheanstalkInterface
     */
    protected function getPheanstalk()
    {
        return $this->getQueueManager()->getPheanstalk();
    }

    /**
     * @return object
     */
    protected function getServerStats()
    {
        return $this->getPheanstalk()->stats();
    }

    /**
     * @param string $tube
     *
     * @return object
     */
    protected function getTubeStats($tube)
    {
        /** @var Reader $statisticoReader */
        $statisticoReader = $this->getConfig()->get('statistico.reader');

        $data = $this->getPheanstalk()->statsTube($tube);

        $granularity = 'minutes';
        $factor = 60;
        $from = new \DateTime('-30 minutes');
        $to = null;

        $rpm = $statisticoReader->queryCounts('job.execute.'. $tube, $granularity, $from);
        $rpm = $this->completeCountsData($rpm, $factor, $from, $to);

        $data['rpm'] = $rpm;
        $data['timings'] = $statisticoReader->queryTimings('job.'. $tube, 'seconds', new \DateTime('-60 seconds'));

        $data['timing_avg'] = $this->avg($data['timings']);
        $data['rpm_avg'] = $this->avg($data['rpm']);

        return $data;
    }

    /**
     * @inheritdoc
     */
    public function getTemplateName()
    {
        return $this->template;
    }

    /**
     * @param array $set
     *
     * @return float|int
     */
    protected function avg(array $set)
    {
        $count = count($set);
        $avg = 0;
        array_pop($set);
        if ($count > 0) {
            $total = array_sum($set);
            $avg = round($total / $count);
        }

        return $avg;
    }

    /**
     * @param array $data
     * @param int $factor
     * @param \DateTime $from
     * @param \DateTime $to
     *
     * @return array
     */
    protected function completeCountsData(
        array $data,
        $factor,
        \DateTime $from,
        \DateTime $to = null
    ) {
        reset($data);

        $to = $to ?: new \DateTime();

        // factor diff
        $mod = ($from->getTimestamp() % $factor);

        if ($data) {
            $min = min($from->getTimestamp() - $mod, key($data)); // first key
        } else {
            $min = $from->getTimestamp();
        }

        $max = $to->getTimestamp();

        $retval = [];

        for ($t = $min; $t <= $max; $t += $factor) {
            $retval[$t] = isset($data[$t]) ? $data[$t] : 0;
        }

        return $retval;
    }

    /**
     * @param array $data
     * @param int $factor
     * @param \DateTime $from
     * @param \DateTime $to
     *
     * @return array
     */
    protected function completeGaugesData(
        array $data,
        $factor,
        \DateTime $from,
        \DateTime $to = null
    ) {
        reset($data);

        $to = $to ?: new \DateTime();

        // factor diff
        $mod = ($from->getTimestamp() % $factor);

        if ($data) {
            $min = min($from->getTimestamp() - $mod, key($data)); // first key
        } else {
            $min = $from->getTimestamp();
        }

        $max = $to->getTimestamp();

        $retval = [];

        $previous = 0;

        for ($t = $min; $t <= $max; $t += $factor) {
            $retval[$t] = isset($data[$t]) ? $data[$t] : $previous;

            $previous = $retval[$t];
        }

        return $retval;
    }
}
