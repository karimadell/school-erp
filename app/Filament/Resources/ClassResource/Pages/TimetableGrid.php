<?php

namespace App\Filament\Resources\ClassResource\Pages;

use App\Filament\Resources\ClassResource;
use Filament\Resources\Pages\Page;
use Filament\Notifications\Notification;

use App\Models\Timetable;
use App\Models\Day;
use App\Models\Period;
use App\Models\Subject;
use App\Models\SchoolClass;

class TimetableGrid extends Page
{
    protected static string $resource = ClassResource::class;

    protected string $view = 'filament.resources.class-resource.pages.timetable-grid';

    public $days;
    public $periods;
    public $subjects;
    public $classId;

    public $selectedSubject = [];
    public $selectedTeacher = [];

    public $dragLessonId = null;

    public function mount($record): void
    {
        $this->classId = $record;

        $this->days = Day::orderBy('order')->get();
        $this->periods = Period::orderBy('order')->get();

        $this->subjects = Subject::with('teachers')
            ->where('is_active', true)
            ->get();
    }

    public function getLesson($dayId, $periodId)
    {
        return Timetable::with(['subject','teacher'])
            ->where('class_id',$this->classId)
            ->where('day_id',$dayId)
            ->where('period_id',$periodId)
            ->first();
    }

    public function saveLesson($dayId,$periodId)
    {
        $subjectId = $this->selectedSubject[$dayId][$periodId] ?? null;
        $teacherId = $this->selectedTeacher[$dayId][$periodId] ?? null;

        if(!$subjectId || !$teacherId){
            return;
        }

        $teacherConflict = Timetable::where('teacher_id',$teacherId)
            ->where('day_id',$dayId)
            ->where('period_id',$periodId)
            ->where('class_id','!=',$this->classId)
            ->exists();

        if($teacherConflict){

            Notification::make()
                ->title('Teacher Conflict')
                ->body('Teacher already has lesson at this time')
                ->danger()
                ->send();

            return;
        }

        Timetable::updateOrCreate(
            [
                'class_id'=>$this->classId,
                'day_id'=>$dayId,
                'period_id'=>$periodId
            ],
            [
                'subject_id'=>$subjectId,
                'teacher_id'=>$teacherId
            ]
        );

        Notification::make()
            ->title('Lesson Saved')
            ->success()
            ->send();
    }

    public function startDrag($lessonId): void
    {
        $this->dragLessonId = $lessonId;
    }

    public function moveLesson($targetDayId,$targetPeriodId): void
    {
        if(!$this->dragLessonId){
            return;
        }

        $draggedLesson = Timetable::find($this->dragLessonId);

        if(!$draggedLesson){
            $this->dragLessonId = null;
            return;
        }

        $sourceDayId = $draggedLesson->day_id;
        $sourcePeriodId = $draggedLesson->period_id;
        $classId = $draggedLesson->class_id;

        $teacherConflict = Timetable::where('teacher_id',$draggedLesson->teacher_id)
            ->where('day_id',$targetDayId)
            ->where('period_id',$targetPeriodId)
            ->where('id','!=',$draggedLesson->id)
            ->exists();

        if($teacherConflict){

            Notification::make()
                ->title('Teacher Conflict')
                ->danger()
                ->send();

            $this->dragLessonId = null;
            return;
        }

        $targetLesson = Timetable::where('class_id',$classId)
            ->where('day_id',$targetDayId)
            ->where('period_id',$targetPeriodId)
            ->where('id','!=',$draggedLesson->id)
            ->first();

        if(!$targetLesson){

            $draggedLesson->update([
                'day_id'=>$targetDayId,
                'period_id'=>$targetPeriodId
            ]);

            $this->dragLessonId = null;
            return;
        }

        $targetLesson->update([
            'day_id'=>$sourceDayId,
            'period_id'=>$sourcePeriodId
        ]);

        $draggedLesson->update([
            'day_id'=>$targetDayId,
            'period_id'=>$targetPeriodId
        ]);

        $this->dragLessonId = null;
    }

    public function generateTimetable(): void
    {
        $classes = SchoolClass::all();

        foreach($classes as $class){

            // حذف الجدول القديم
            Timetable::where('class_id',$class->id)->delete();

            $pool = [];

            foreach($this->subjects as $subject){

                $hours = (int)($subject->hours_per_week ?? 0);

                for($i=0;$i<$hours;$i++){
                    $pool[] = $subject;
                }
            }

            shuffle($pool);

            $lastSubjectId = null;

            foreach($this->days as $day){

                $usedToday = [];

                foreach($this->periods as $period){

                    if(empty($pool)){
                        continue;
                    }

                    $availableSubjects = collect($pool)
                        ->where('id','!=',$lastSubjectId)
                        ->whereNotIn('id',$usedToday);

                    if($availableSubjects->isEmpty()){
                        $availableSubjects = collect($pool);
                    }

                    $subject = $availableSubjects->random();

                    $teacher = $subject->teachers()
                        ->whereDoesntHave('timetables',function($q) use($day,$period){

                            $q->where('day_id',$day->id)
                              ->where('period_id',$period->id);

                        })
                        ->inRandomOrder()
                        ->first();

                    if(!$teacher){
                        continue;
                    }

                    Timetable::create([
                        'class_id'=>$class->id,
                        'day_id'=>$day->id,
                        'period_id'=>$period->id,
                        'subject_id'=>$subject->id,
                        'teacher_id'=>$teacher->id
                    ]);

                    $lastSubjectId = $subject->id;
                    $usedToday[] = $subject->id;

                    foreach($pool as $index=>$poolSubject){

                        if($poolSubject->id === $subject->id){

                            unset($pool[$index]);
                            $pool = array_values($pool);
                            break;

                        }
                    }
                }
            }
        }

        Notification::make()
            ->title('Smart Timetable Generated')
            ->success()
            ->send();
    }
}