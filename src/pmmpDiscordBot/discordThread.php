<?php

namespace pmmpDiscordBot;

use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Helpers\Collection;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Discord\Parts\Embed\Embed;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\Server;
use pocketmine\snooze\SleeperNotifier;
use pocketmine\thread\Thread;
use React\EventLoop\Loop;

class discordThread extends Thread{
	public $file;

	public $started = false;
	public $content;
	public $no_vendor;
	/** @var string */
	public $games = "no games";
	/** @var int */
	public $playercount = 0;
	/** @var string */
	public $version;
	private $token;
	public $send_guildId;
	public $send_channelId;
	public $receive_channelId;
	public $send_interval;
	public $receive_check_interval;
	public $debug;

	protected $D2P_Queue;
	protected $P2D_Queue;

	protected $stoped = false;

	/** pmmp api */

	/** @var \ThreadedLogger */
	protected $logger;
	/** @var SleeperNotifier */
	protected $notifier;
	/** @var ConsoleCommandSender */
	private static $consoleSender;
	/** @var ?Message */
	private static $targetmessage;

	public function __construct($file, $no_vendor, string $token, string $send_guildId, string $send_channelId, string $receive_channelId, int $send_interval = 1, bool $debug = false){
		$this->file = $file;
		$this->no_vendor = $no_vendor;
		$this->token = $token;
		$this->send_guildId = $send_guildId;
		$this->send_channelId = $send_channelId;
		$this->receive_channelId = $receive_channelId;

		$this->send_interval = $send_interval;

		$this->debug = $debug;

		$this->D2P_Queue = new \Threaded;
		$this->P2D_Queue = new \Threaded;

		$server = Server::getInstance();
		self::$consoleSender = new ConsoleCommandSender($server, $server->getLanguage());

		$this->logger = new \PrefixedLogger(Server::getInstance()->getLogger(), "StatusBot");
		$this->initSleeperNotifier();
		$this->start();

		$this->version = ProtocolInfo::MINECRAFT_VERSION;
	}

	private function initSleeperNotifier() : void{
		if(isset($this->notifier)) throw new \LogicException("SleeperNotifier has already been initialized.");
		$this->notifier = new SleeperNotifier();
		Server::getInstance()->getTickSleeper()->addNotifier($this->notifier, function(){
			$this->onWake();
		});
	}

	protected function onRun() : void{
		ini_set('memory_limit', '-1');

		if(!$this->no_vendor){
			include $this->file."vendor/autoload.php";
		}

		$loop = Loop::get();

		$debug = $this->debug;
		$logger = new Logger('Logger');
		if($debug === true){
			$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));
		}else{
			//$logger->pushHandler(new NullHandler());
			$logger->pushHandler(new StreamHandler('php://stdout', Logger::WARNING));
		}

		$discord = new \Discord\Discord([
			'token' => $this->token,
			'loop' => $loop,
			'logger' => $logger
		]);

		$timer = $loop->addPeriodicTimer(1, function() use ($discord){
			if($this->isKilled){
				$this->onStop($discord);
				$this->started = false;
				return;
			}
		});

		$timer = $loop->addPeriodicTimer(60, function() use ($discord){
			if($this->isKilled) return;
			$this->task($discord);
		});

		unset($this->token);

		$discord->on("ready", function(Discord $discord){
			$this->started = true;
			$this->logger->info("Bot is ready.");
			// Listen for events here
			$botUserId = $discord->user->id;
			$receive_channelId = $this->receive_channelId;

			/** @var Channel $channel */
			$channel = $discord->getChannel($this->send_channelId);
			$channel->getMessageHistory([
				'limit' => 3,
			])->done(function(Collection $messages) use ($discord, $channel){
				foreach($messages as $message){
					/** @var Message $message */
					if($message->author->id === $discord->id){
						self::$targetmessage = $message;
						$this->task($discord);
						break;
					}
				}
				if(!isset(self::$targetmessage)){
					$embed = $this->getEmbed($discord, true);
					$channel->sendMessage("", false, $embed)->then(function(Message $message) use ($discord){
						self::$targetmessage = $message;
						$this->task($discord);
					});
				}
			});
//			$discord->on(Event::MESSAGE_CREATE, function(Message $message) use ($botUserId, $receive_channelId){

//				$message->
//				if($message->channel_id === $receive_channelId){
//					if($message->type !== Message::TYPE_NORMAL) return;//join message etc...
//					if($message->author->id === $botUserId) return;
//					$this->D2P_Queue[] = serialize([
//						'username' => $message->author->username,
//						'content' => $message->content
//					]);
//					/** @see onWake() */
//					$this->notifier->wakeupSleeper();
//				}
//			});
		});
		$discord->run();
	}

	private function task(Discord $discord){
		if(!$this->started||!isset(self::$targetmessage)) return;
		var_dump("onTask");
		$embed = $this->getEmbed($discord, true);
		$builder = MessageBuilder::new()->setEmbeds([$embed]);
		self::$targetmessage->edit($builder);

	}

	private function onStop(Discord $discord){
		if($this->stoped){
			$this->logger->info("killing discord thread");
			$discord->close();
			$discord->loop->stop();
			return;
		}
		if(!isset(self::$targetmessage)) return;
		$this->logger->info("Editing server status offline");
		$embed = $this->getEmbed($discord, false);
		$builder = MessageBuilder::new()->setEmbeds([$embed]);
		self::$targetmessage->edit($builder)->then(function(){
			$this->stoped = true;
		});
		self::$targetmessage = null;
	}

	private function getEmbed(Discord $discord, bool $online) : Embed{
		$embed = new Embed($discord);
		$embed->setTitle("Server Status");
		$embed->addFieldValues("Server Status", $online ? "ONLINE" : "OFFLINE", false);
		$embed->addFieldValues("players", $this->playercount."/50", true);
		$embed->addFieldValues("version", $this->version, true);
		if($online){
			$embed->addFieldValues("games", $this->games);
		}
		$embed->setTimestamp();
		return $embed;
	}

	//===メインスレッド呼び出し専用関数にてございます...===
	public function shutdown(){
		$this->isKilled = true;
		//usleep(500000);
		//$this->quit();
	}

	/**
	 * スレッドを停止します。
	 *
	 * @internal pmmp内部より、プラグイン無効化後に起動
	 * @return void
	 */
	public function quit() : void{
		Server::getInstance()->getTickSleeper()->removeNotifier($this->notifier);
		parent::quit();
	}

	public function sendMessage(string $message){
		$this->P2D_Queue[] = serialize($message);
	}

	public function fetchMessages(){
		$messages = [];
		while(count($this->D2P_Queue) > 0){
			$messages[] = unserialize($this->D2P_Queue->shift());
		}
		return $messages;
	}

	private function onWake() : void{
		foreach($this->fetchMessages() as $message){
			$content = $message["content"];
			var_dump($content);
			if($content === ""){
				continue;
			}

			if($content[0] === "/"||$content[0] === "!"||$content[0] === "?"){
				Server::getInstance()->dispatchCommand(self::$consoleSender, substr($content, 1));
			}else{
				Server::getInstance()->dispatchCommand(self::$consoleSender, "me ".$content);
			}
		}
	}
}
