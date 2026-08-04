<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Fee;
use App\Models\StudentServiceSubscription;
use App\Models\StudentServiceSubscriptionEvent;
use App\Models\User;
use App\Services\StudentServiceSubscriptionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudentSubscriptionLifecycleService
{
    public function __construct(private StudentServiceSubscriptionService $creator) {}

    public function create(Enrollment $enrollment, Fee $fee, array $data, User $actor): StudentServiceSubscription
    {
        return DB::transaction(function () use ($enrollment, $fee, $data, $actor) {
            if (! $fee->is_active) throw ValidationException::withMessages(['fee_id'=>'Услуга неактивна.']);
            $start = Carbon::parse($data['start_date']); $end = filled($data['end_date'] ?? null) ? Carbon::parse($data['end_date']) : null;
            if ($end && $end->lt($start)) throw ValidationException::withMessages(['end_date'=>'Дата окончания не может быть раньше даты начала.']);
            try {
                $attributes = collect($data)->only(['start_date','end_date','quantity','metadata','negotiated_price','negotiated_reason'])->all();
                $attributes['status'] = StudentServiceSubscription::STATUS_ACTIVE;
                $subscription = $this->creator->subscribe($enrollment, $fee, $attributes, $actor);
            } catch (\App\Exceptions\DuplicateSubscriptionException $exception) {
                throw ValidationException::withMessages(['fee_id'=>'Для ученика уже существует пересекающаяся активная услуга.']);
            }
            $this->event($subscription, 'created', $start, $data['reason'] ?? 'Услуга подключена.', $actor);
            $this->audit($subscription, 'created', $actor);
            return $subscription;
        });
    }

    public function pause(StudentServiceSubscription $subscription, string $date, string $reason, User $actor): StudentServiceSubscription
    { return $this->transition($subscription, 'paused', $date, $reason, $actor, 'suspended'); }
    public function resume(StudentServiceSubscription $subscription, string $date, ?string $reason, User $actor): StudentServiceSubscription
    { if ($subscription->status !== StudentServiceSubscription::STATUS_SUSPENDED) throw ValidationException::withMessages(['status'=>'Возобновить можно только приостановленную услугу.']); return $this->transition($subscription, 'resumed', $date, $reason, $actor, 'active'); }
    public function end(StudentServiceSubscription $subscription, string $date, string $reason, User $actor): StudentServiceSubscription
    { return $this->transition($subscription, 'ended', $date, $reason, $actor, StudentServiceSubscription::STATUS_COMPLETED); }

    public function changeVariant(StudentServiceSubscription $old, array $data, User $actor): StudentServiceSubscription
    {
        return DB::transaction(function () use ($old, $data, $actor) {
            $effective = Carbon::parse($data['start_date']);
            if ($effective->lte($old->start_date)) throw ValidationException::withMessages(['start_date'=>'Новая версия должна начинаться после начала текущей услуги.']);
            $previousEnd = $effective->copy()->subDay();
            if ($old->end_date && $previousEnd->gt($old->end_date)) throw ValidationException::withMessages(['start_date'=>'Дата изменения выходит за пределы текущей услуги.']);
            $old->forceFill(['end_date'=>$previousEnd, 'status'=>StudentServiceSubscription::STATUS_COMPLETED])->save();
            $this->event($old, 'version_ended', $previousEnd, 'Создана новая версия услуги.', $actor);
            $new = $this->create($old->enrollment, $old->fee, array_merge($data, ['start_date'=>$effective->toDateString()]), $actor);
            $this->event($new, 'version_started', $effective, 'Изменение варианта услуги.', $actor);
            return $new;
        });
    }

    private function transition(StudentServiceSubscription $subscription, string $event, string $date, ?string $reason, User $actor, string $status): StudentServiceSubscription
    {
        return DB::transaction(function () use ($subscription, $event, $date, $reason, $actor, $status) {
            $date = Carbon::parse($date);
            if ($event === 'paused' && $subscription->status !== StudentServiceSubscription::STATUS_ACTIVE) throw ValidationException::withMessages(['status'=>'Приостановить можно только активную услугу.']);
            if ($event === 'ended' && in_array($subscription->status, [StudentServiceSubscription::STATUS_COMPLETED, StudentServiceSubscription::STATUS_CANCELLED], true)) throw ValidationException::withMessages(['status'=>'Услуга уже завершена.']);
            if ($date->lt($subscription->start_date)) throw ValidationException::withMessages(['effective_date'=>'Дата действия не может быть раньше начала услуги.']);
            $subscription->forceFill(['status'=>$status, 'end_date'=>$event === 'ended' ? $date->toDateString() : $subscription->end_date])->save();
            $this->event($subscription, $event, $date, $reason, $actor); $this->audit($subscription, $event, $actor);
            return $subscription->fresh();
        });
    }

    private function event(StudentServiceSubscription $subscription, string $type, Carbon $date, ?string $reason, User $actor): void
    { StudentServiceSubscriptionEvent::create(['subscription_id'=>$subscription->id,'event_type'=>$type,'effective_date'=>$date->toDateString(),'reason'=>$reason,'created_by'=>$actor->id]); }
    private function audit(StudentServiceSubscription $subscription, string $action, User $actor): void
    { AuditLog::create(['user_id'=>$actor->id,'action'=>'subscription_'.$action,'model'=>'StudentServiceSubscription','model_id'=>$subscription->id,'new_values'=>['status'=>$subscription->status],'ip'=>request()->ip(),'user_agent'=>request()->userAgent()]); }
}
