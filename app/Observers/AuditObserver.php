<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditObserver
{
    protected function log(string $action, Model $model, array $old = null): void
    {
        AuditLog::create([
            'user_id'    => auth()->id(),
            'action'     => $action,
            'model'      => class_basename($model),
            'model_id'   => $model->id,
            'old_values' => $old,
            'new_values' => $model->getAttributes(),
            'ip'         => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    public function created(Model $model): void
    {
        $this->log('created', $model);
    }

    public function updated(Model $model): void
    {
        $this->log('updated', $model, $model->getOriginal());
    }

    public function deleted(Model $model): void
    {
        $this->log('deleted', $model, $model->getOriginal());
    }
}