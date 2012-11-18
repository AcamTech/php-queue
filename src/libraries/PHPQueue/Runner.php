<?php
namespace PHPQueue;
abstract class Runner
{
	const RUN_USLEEP = 1000000;
	public $queueOptions;
	public $queueName;
	private $queue;
	public $logger;
	public $logPath;
	public $logLevel;

	public function __construct($queue='', $options=array())
	{
		if (!empty($queue))
		{
			$this->queueName = $queue;
		}
		if (!empty($options))
		{
			$this->queueOptions = $options;
		}
		if (
			   !empty($this->queueOptions['logPath'])
			&& is_writable($this->queueOptions['logPath'])
		)
		{
			$this->logPath = $this->queueOptions['logPath'];
		}
		if ( !empty($this->queueOptions['logLevel']) )
		{
			$this->logLevel = $this->queueOptions['logLevel'];
		}
		else
		{
			$this->logLevel = Logger::INFO;
		}
		return $this;
	}

	public function run()
	{
		$this->setup();
		$this->beforeLoop();
		while (true)
		{
			$this->loop();
		}
	}

	public function setup()
	{
		if (empty($this->logPath))
		{
			$baseFolder = dirname(dirname(__DIR__));
			$this->logPath = sprintf(
								  '%s/demo/runners/logs/'
								, $baseFolder
							);
		}
		$logFileName = sprintf('%s-%s.log', $this->queueName, date('Ymd'));
		$this->logger = \PHPQueue\Logger::startLogger(
							  $this->queueName
							, $this->logLevel
							, $this->logPath . $logFileName
						);
	}

	protected function beforeLoop()
	{
		if (empty($this->queueName))
		{
			throw new \PHPQueue\Exception('Queue name is invalid');
		}
		$this->queue = \PHPQueue\Base::getQueue($this->queueName, $this->queueOptions);
	}

	protected function loop()
	{
		$sleepTime = self::RUN_USLEEP;
		$newJob = null;
		try
		{
			$newJob = \PHPQueue\Base::getJob($this->queue);
		}
		catch (Exception $ex)
		{
			$this->logger->addError($ex->getMessage());
			$sleepTime = self::RUN_USLEEP * 5;
		}
		if (empty($newJob))
		{
			$this->logger->addNotice("No Job found.");
			$sleepTime = self::RUN_USLEEP * 10;
		}
		else
		{
			$this->logger->addInfo(sprintf("Running new job (%s) with worker: %s", $newJob->jobId, $newJob->worker));
			try
			{
				$worker = \PHPQueue\Base::getWorker($newJob->worker);
				\PHPQueue\Base::workJob($worker, $newJob);
				$this->logger->addInfo(sprintf('Worker is done. Updating job (%s). Result:', $newJob->jobId), $worker->resultData);
				return \PHPQueue\Base::updateJob($this->queue, $newJob->jobId, $worker->resultData);
			}
			catch (Exception $ex)
			{
				$this->logger->addError($ex->getMessage());
				$this->logger->addInfo(sprintf('Releasing job (%s).', $newJob->jobId));
				$this->queue->releaseJob($newJob->jobId);
				throw $ex;
			}
		}
		$this->logger->addInfo('Sleeping ' . ceil($sleepTime / 1000000) . ' seconds.');
		usleep($sleepTime);
	}
}
?>