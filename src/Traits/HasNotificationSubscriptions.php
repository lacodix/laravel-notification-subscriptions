<?php

namespace Lacodix\LaravelNotificationSubscriptions\Traits;

use Lacodix\LaravelNotificationSubscriptions\Models\NotificationSubscription;

trait HasNotificationSubscriptions
{
    /**
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<NotificationSubscription, $this>
     */
    public function notificationSubscriptions()
    {
        return $this->morphMany(NotificationSubscription::class, 'notifiable');
    }

    public function subscribe($type, $channel = '*', $model = null)
    {
        $this->unsetRelation('notificationSubscriptions');

        $subscription = $this->findSubscription($type, $channel, $model);

        if ($subscription) {
            return $subscription->resubscribe();
        }

        return $this->createSubscription($type, $channel, $model);
    }

    public function unsubscribe($type, $channel = '*', $model = null)
    {
        $this->unsetRelation('notificationSubscriptions');

        $subscription = $this->findSubscription($type, $channel, $model);

        if ($subscription) {
            return $subscription->unsubscribe();
        }

        return $this->createSubscription($type, $channel, $model, true);
    }

    public function findSubscription($type, $channel = '*', $model = null)
    {
        if ($this->relationLoaded('notificationSubscriptions')) {
            $modelType = $model ? get_class($model) : null;
            $modelId = optional($model)->id;

            return $this->notificationSubscriptions->first(function ($subscription) use ($type, $channel, $modelType, $modelId) {
                return $subscription->type === $type
                    && $subscription->channel === $channel
                    && $subscription->model_type === $modelType
                    && ($modelId === null ? $subscription->model_id === null : $subscription->model_id !== null && $subscription->model_id == $modelId);
            });
        }

        return $this->notificationSubscriptions()->where('type', $type)->where('channel', $channel)->model($model)->first();
    }

    public function createSubscription($type, $channel = '*', $model = null, $unsubscribe = false)
    {
        $this->unsetRelation('notificationSubscriptions');

        return $this->notificationSubscriptions()->create([
            'type'            => $type,
            'channel'         => $channel,
            'model_type'      => $model ? get_class($model) : null,
            'model_id'        => optional($model)->id,
            'unsubscribed_at' => $unsubscribe ? $this->freshTimestamp() : null,
        ]);
    }

    public function isSubscribed($type, $channel, $model = null, $optin = [])
    {
        $subscription = $this->findSubscription($type, $channel, $model) ?: $this->findSubscription($type, '*', $model);

        if (!$subscription && $model) {
            $subscription = $this->findSubscription($type, $channel) ?: $this->findSubscription($type, '*');
        }

        if (!$subscription) {
            return !in_array($channel, $optin);
        }

        return $subscription->isSubscribed();
    }

    public function resetSubscriptions($type, $model = null)
    {
        $this->unsetRelation('notificationSubscriptions');

        $this->notificationSubscriptions()->where('type', $type)->model($model)->delete();

        return $this;
    }
}
