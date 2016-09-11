<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests;
use Log;
use Telegram;
use Telegram\Bot\Api;
use Telegram\Bot\Actions;
use App\Bots\Commands\RsvpBot\CommandUtil;
use App\Bots\QuestionProcessor\QuestionProcessor;
use App\Attendee;
use App\Event;
use Telegram\Bot\Objects\Update;

class RsvpBotController extends Controller
{

    protected $telegram;
    protected $questionProcessor;

    public function __construct()
    {
        $this->telegram = new Api(env('RSVPBOT_TOKEN'));
        $this->telegram->addCommands([
            \App\Bots\Commands\HazeBot\StartCommand::class,
            \App\Bots\Commands\RsvpBot\CreateEventCommand::class,
            \App\Bots\Commands\RsvpBot\ViewEventCommand::class,
            \App\Bots\Commands\RsvpBot\DeleteEventCommand::class,
            \App\Bots\Commands\RsvpBot\AttendingCommand::class,
            \App\Bots\Commands\RsvpBot\CoupleCommand::class,
            \App\Bots\Commands\RsvpBot\FriendCommand::class,
            \App\Bots\Commands\RsvpBot\WithdrawCommand::class,
            Telegram\Bot\Commands\HelpCommand::class
        ]);

        $this->questionProcessor = new QuestionProcessor($this->telegram);
    }

    public function setWebhook()
    {
        $response = $this->telegram->setWebhook(['url' => 'https://pickira.com/rsvpbot/' . env('RSVPBOT_TOKEN') . '/webhook']);
        return $response;
    }

    public function webhook()
    {
        $update = $this->telegram->commandsHandler(true);

        $message = $update->getMessage();

        // First, check the question that the update was replying to
        if($message->getReplyToMessage() == null) {

            return response()->json(["status" => "success"]);
        }

        $this->questionProcessor->addQuestions([
            new \App\Bots\QuestionProcessor\RsvpBot\CreateEventQuestion('What is your event?', 'event.create')
        ]);

        $this->telegram->sendChatAction(['chat_id' => $message->getChat()->getId(), 'action' => Actions::TYPING]);

        $this->questionProcessor->process($message);

        // ghetto implmentation of conversation
        // Check the correct message that this current update is replying to
        if ($message->getReplyToMessage()->getText() == "What is your friend's name?") {
            // normal processing
            $event    = Event::where('chat_id', $message->getChat()->getId())->first(); // check for event

            if (!$event) {
                $this->telegram->sendMessage(['chat_id' => $message->getChat()->getId(), 'text' => "You have no event to attend."]);
                return response()->json(["status" => "success"]);
            }

            //get friend's name
            $name     = $message->getText();

            // new up an attendee if not in record
            $attendee = Attendee::firstOrNew(['event_id' => $event['id'], 'username' => $name]);

            $attendee->save();


            // prepare the text and send
            $text = CommandUtil::getAttendanceList($event);

            // send reply back to user.
            $this->telegram->sendMessage(['chat_id' => $message->getChat()->getId(), 'text' => $text ]);
        }

        return response()->json(["status" => "success"]);
    }

    public function removeWebhook()
    {
        $response = $this->telegram->removeWebhook();
        return $response;
    }
}
