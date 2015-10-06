<?php

namespace TreeHouse\ConanWorkerBundle\Command;

use Fieg\Statistico\Statistico;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TreeHouse\WorkerBundle\QueueManager;

class CollectStatisticsCommand extends Command
{
    /**
     * @var Statistico
     */
    protected $statistico;

    /**
     * @var QueueManager
     */
    protected $manager;

    /**
     * @param QueueManager $queueManager
     * @param Statistico $statistico
     */
    public function __construct(QueueManager $queueManager, Statistico $statistico)
    {
        $this->manager = $queueManager;
        $this->statistico = $statistico;

        parent::__construct();
    }

    /**
     * @inheritdoc
     */
    protected function configure()
    {
        $this
            ->setName('conan:worker:collect-statistics')
            ->setDescription('Collects statistics about the worker (use as cronjob)')
        ;
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $fields = [
            'action'   => 'name',
            'workers'  => 'current-watching',
            'reserved' => 'current-jobs-reserved',
            'ready'    => 'current-jobs-ready',
            'urgent'   => 'current-jobs-urgent',
            'delayed'  => 'current-jobs-delayed',
            'buried'   => 'current-jobs-buried',
        ];

        $actions = array_keys($this->manager->getExecutors());

        $rows = [];
        foreach ($actions as $action) {
            if (!$stats = $this->manager->getActionStats($action)) {
                $stats = array_combine(array_values($fields), array_fill(0, sizeof($fields), '-'));
                $stats['name'] = $action;
            }

            $rows[$action] = array_map(
                function ($field) use ($stats) {
                    return $stats[$field];
                },
                $fields
            );
        }

        ksort($rows);

        foreach ($rows as $action => $stats) {
            $this->statistico->gauge(sprintf('tube.%s.workers', $action), $stats['workers']);
            $this->statistico->gauge(sprintf('tube.%s.reserved', $action), $stats['reserved']);
            $this->statistico->gauge(sprintf('tube.%s.ready', $action), $stats['ready']);
            $this->statistico->gauge(sprintf('tube.%s.urgent', $action), $stats['urgent']);
            $this->statistico->gauge(sprintf('tube.%s.delayed', $action), $stats['delayed']);
            $this->statistico->gauge(sprintf('tube.%s.buried', $action), $stats['buried']);
        }

        $table = new Table($output);
        $table->setHeaders(array_keys($fields));
        $table->addRows($rows);


        $output->writeln('Following statistics collected:' . "\n");

        $table->render();
    }
}
