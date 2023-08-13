<?php

namespace App\Issues\Types\GitHub;

use App\Issues\Data\MemberData;
use App\Issues\Types\IssueBase;

class UsernameNotListedInMembersTeam extends IssueBase
{
    private MemberData $member;

    public function __construct(MemberData $member)
    {
        $this->member = $member;
    }

    public static function getIssueNumber(): int
    {
        return 400;  // auto-generated based on namespace and existing issues
    }

    public static function getIssueTitle(): string
    {
        return "GitHub: Username not listed in team";
    }

    public function getIssueText(): string
    {
        return "{$this->member->first_name} {$this->member->last_name} is an active member but their GitHub username ({$this->member->githubUsername}) is not in the \"members\" team";
    }
}
