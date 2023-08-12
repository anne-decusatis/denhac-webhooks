<?php

namespace App\Issues\Checkers;


use App\Issues\IssueData;
use App\Issues\Types\Slack\MemberDoesNotHaveASlackAccount;
use Illuminate\Support\Collection;

class MissingSlackUsers implements IssueCheck
{
    use IssueCheckTrait;
    use SlackMembershipHelper;

    private IssueData $issueData;

    public function __construct(IssueData $issueData)
    {
        $this->issueData = $issueData;
    }

    public function generateIssues(): void
    {
        $members = $this->issueData->members();
        $slackUsers = $this->issueData->slackUsers();

        $members
            ->each(function ($member) use ($slackUsers) {
                if (! $member['is_member']) {
                    return;
                }

                $slackForMember = $slackUsers
                    ->filter(function ($user) use ($member) {
                        return $member['slack_id'] == $user['id'];
                    });

                if ($slackForMember->count() == 0) {
                    $this->issues->add(new MemberDoesNotHaveASlackAccount($member));
                }
            });
    }
}
