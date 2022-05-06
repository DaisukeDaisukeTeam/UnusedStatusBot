<?php
declare(strict_types=1);

namespace pmmpDiscordBot;

use pocketmine\scheduler\Task;
use pocketmine\Server;

class CollectorTask extends Task{
	private discordThread $thread;

	public function __construct(discordThread $thread){
		$this->thread = $thread;
	}


	public function onRun() : void{
		$this->thread->playercount = count(Server::getInstance()->getOnlinePlayers());
		$event = new GameStatusCollectionEvent($this);
		$event->call();
		$result = "";
		foreach($event->getCollection() as $name => $array){
			foreach($array as $item){
				$result .= $name." ".$item."\n";
			}
		}
		if($result === ""){
			$result = "no games";
		}
		$this->thread->games = trim($result);
	}
}