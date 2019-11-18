<?php

namespace App\Aggregates;

use App\CardUpdateRequest;
use App\StorableEvents\CardActivated;
use App\StorableEvents\CardAdded;
use App\StorableEvents\CardDeactivated;
use App\StorableEvents\CardRemoved;
use App\StorableEvents\CardSentForActivation;
use App\StorableEvents\CardSentForDeactivation;
use App\StorableEvents\CardStatusUpdated;
use App\StorableEvents\CustomerCreated;
use App\StorableEvents\CustomerUpdated;
use App\StorableEvents\MembershipActivated;
use App\StorableEvents\SubscriptionCreated;
use App\StorableEvents\SubscriptionStatusChanged;
use App\StorableEvents\SubscriptionUpdated;
use Ramsey\Uuid\Uuid;
use Spatie\EventSourcing\AggregateRoot;

final class MembershipAggregate extends AggregateRoot
{
    private $customerId;

    private $cardsOnAccount;
    private $cardsNeedingActivation; // They need activation only if this person is a confirmed member
    private $cardsSentForActivation;
    private $cardsSentForDeactivation;

    private $subscriptionStatus = null;
    private $currentlyAMember = null;

    public function __construct()
    {
        $this->cardsOnAccount = collect();
        $this->cardsNeedingActivation = collect();
        $this->cardsSentForActivation = collect();
        $this->cardsSentForDeactivation = collect();
    }

    /**
     * @param string $customerId
     * @return MembershipAggregate
     */
    public static function make(string $customerId): AggregateRoot
    {
        $uuid = Uuid::uuid5(UUID::NAMESPACE_OID, $customerId);
        $aggregateRoot = MembershipAggregate::retrieve($uuid);
        $aggregateRoot->customerId = $customerId;

        return $aggregateRoot;
    }

    public function createCustomer($customer)
    {
        $this->recordThat(new CustomerCreated($customer));

        $this->handleCards($customer);

        return $this;
    }

    public function updateCustomer($customer)
    {
        $this->recordThat(new CustomerUpdated($customer));

        $this->handleCards($customer);

        return $this;
    }

    public function createSubscription($subscription)
    {
        $this->recordThat(new SubscriptionCreated($subscription));

        $this->handleSubscriptionStatus($subscription["id"], $subscription["status"]);

        return $this;
    }

    public function updateSubscription($subscription)
    {
        $this->recordThat(new SubscriptionUpdated($subscription));

        $this->handleSubscriptionStatus($subscription["id"], $subscription["status"]);

        return $this;
    }

    public function updateCardStatus(CardUpdateRequest $cardUpdateRequest, $status)
    {
        $this->recordThat(new CardStatusUpdated(
            $cardUpdateRequest->type,
            $cardUpdateRequest->customer_id,
            $cardUpdateRequest->card
        ));

        if ($status == CardUpdateRequest::STATUS_SUCCESS) {
            if ($cardUpdateRequest->type == CardUpdateRequest::ACTIVATION_TYPE) {
                $this->recordThat(new CardActivated($this->customerId, $cardUpdateRequest->card));
            }
            if ($cardUpdateRequest->type == CardUpdateRequest::DEACTIVATION_TYPE) {
                $this->recordThat(new CardDeactivated($this->customerId, $cardUpdateRequest->card));
            }

            $message = "Card update request type wasn't one of the expected values: {$cardUpdateRequest->type}";
            report(new \Exception($message));
        } else {
            $message = "Card update (Customer: $cardUpdateRequest->customer_id, "
                . "Card: $cardUpdateRequest->card, Type: $cardUpdateRequest->type) "
                . "not successful";
            report(new \Exception($message));
        }

        return $this;
    }

    private function handleCards($customer)
    {
        $metadata = collect($customer["meta_data"]);
        $cardMetadata = $metadata->firstWhere('key', 'access_card_number');

        if ($cardMetadata == null) {
            return;
        }

        $cardField = $cardMetadata["value"];

        $cardList = collect(explode(",", $cardField));
        foreach ($cardList as $card) {
            if (!$this->cardsOnAccount->contains($card)) {
                $this->recordThat(new CardAdded($this->customerId, $card));

                if ($this->isActiveMember()) {
                    $this->recordThat(new CardSentForActivation($this->customerId, $card));
                }
            }
        }

        foreach ($this->cardsOnAccount as $card) {
            if (!$cardList->contains($card)) {
                $this->recordThat(new CardRemoved($this->customerId, $card));
                $this->recordThat(new CardSentForDeactivation($this->customerId, $card));
            }
        }
    }

    protected function applyCardAdded(CardAdded $event)
    {
        $this->cardsOnAccount->push($event->cardNumber);
        $this->cardsNeedingActivation->push($event->cardNumber);
    }

    protected function applyCardSentForActivation(CardSentForActivation $event)
    {
        $this->cardsNeedingActivation->pull($event->cardNumber);
        $this->cardsSentForActivation->push($event->cardNumber);
    }

    protected function applyCardActivated(CardActivated $event)
    {
        $this->cardsSentForActivation->pull($event->cardNumber);
    }

    protected function applyCardRemoved(CardRemoved $event)
    {
        $this->cardsOnAccount->push($event->cardNumber);
    }

    protected function applyCardSentForDeactivation(CardSentForDeactivation $event)
    {
        $this->cardsSentForDeactivation->push($event->cardNumber);
    }

    protected function applyCardDeactivated(CardDeactivated $event)
    {
        $this->cardsSentForDeactivation->pull($event->cardNumber);
    }

    private function handleSubscriptionStatus($subscriptionId, $newStatus)
    {
        $oldStatus = $this->subscriptionStatus;
        $this->recordThat(new SubscriptionStatusChanged($subscriptionId, $oldStatus, $newStatus));

        if ($oldStatus == null) {
            $oldStatus = $newStatus;
        }

        // TODO Figure out all the state transitions for subscriptions
        if ($oldStatus == "on-hold" && $newStatus == "active") {
            $this->recordThat(new MembershipActivated($this->customerId));

            foreach ($this->cardsNeedingActivation as $card) {
                $this->recordThat(new CardSentForActivation($this->customerId, $card));
            }
        }
    }

    protected function applySubscriptionStatusChanged(SubscriptionStatusChanged $event)
    {
        $this->subscriptionStatus = $event->newStatus;
    }

    protected function applyMemberSubscriptionActivated(MembershipActivated $event)
    {
        $this->currentlyAMember = true;
    }

    private function isActiveMember()
    {
        return $this->currentlyAMember;
    }
}
