<?php

namespace TreeHouse\ConanWorkerBundle\EventListener;

use Fieg\Statistico\Statistico;
use Symfony\Component\Stopwatch\Stopwatch;
use TreeHouse\WorkerBundle\Event\ExecutionEvent;

/**
 * Collects metrics for worker jobs using Statistico
 */
class WorkerStatisticoListener
{
    /**
     * @var \Fieg\Statistico\Statistico
     */
    protected $statistico;

    /**
     * @var Stopwatch
     */
    protected $stopWatch;

    /**
     * Constructor.
     *
     * @param Statistico $statistico
     */
    public function __construct(Statistico $statistico)
    {
        $this->statistico = $statistico;
    }

    /**
     * @param ExecutionEvent $event
     */
    public function onPreJobExecute(ExecutionEvent $event)
    {
        $this->stopWatch = new Stopwatch();

        $this->stopWatch->start('job');

        $this->statistico->increment('job.execute.'.$event->getAction());
    }

    /**
     * @param ExecutionEvent $event
     */
    public function onPostJobExecute(ExecutionEvent $event)
    {
        $info = $this->stopWatch->stop('job');

        $this->statistico->timing('job.execute.' . $event->getAction(), $info->getDuration());
    }
}
