<?php

namespace App\Ai\Agents;

use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\ProviderTool;
use Stringable;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Attributes\Model;

#[Provider(Lab::OpenRouter)]
#[Model('stealth/space-bunny-alpha')] // 'openrouter/free'
class TestAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return 'You are a helpful assistant.';
    }

    // /**
    //  * Get the list of messages comprising the conversation so far.
    //  *
    //  * @return Message[]
    //  */
    // public function messages(): iterable
    // {
    //     return [];
    // }

    /**
     * Get the tools available to the agent.
     *
     * @return list<Agent|Tool|ProviderTool>
     */
    public function tools(): iterable
    {
        return [];
    }
}
