<?php

namespace App\Repositories;

use App\Interfaces\SchedulesInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SchedulesRepository implements SchedulesInterface
{
   /*
    * this will hold the list of all schedules defined in config/app.php file with key 'schedules'
    */
    private $schedulesList;

   /*
    * this will hold the default schedule value defined in config/app.php file with key default_schedule
    */
    private $defaultScheduleValue;
    private $table;
    public function __construct()
    {
        $this->schedulesList = config('app.schedules');
        $this->defaultScheduleValue = config('app.default_schedule');
        $this->table = DB::table('schedules');
    }

    /*
    * defaultSchedule() will be called whenever we get a response with data then this method should be called
    * as this will create a 1 minute schedule.
    * This method will return you the number of minutes so that you can create a delayed job with number of minutes
    * returned by this method.
    */

    public function defautlSchedule()
    {
        return $this->defaultScheduleValue;
    }


    public function setSchedule($segmentId)
    {
        /*
         * here we'll first check whether a schedule for a given segment already exists or not. If there is no schedule already in the database
         * then we'll insert our first schedule.
         * But if there is any schedule present in the DB then we'll retrieve that schedule data from which we'll figure
         * the next request's index and execution time.
         */
        $noPreviousSchedules = $this->table->where('segment_id','=',$segmentId)->count() < 1;
        if ($noPreviousSchedules)
        {
            $requestIndex = 1;
            $requestTime = $this->schedulesList[$requestIndex] -  1;
            $addSchedule = $this->addSchedule($segmentId,$requestIndex);
            if ($addSchedule)
            {
                return $requestTime;
            }
        }
        else{

            $lastScheduleData = $this->table->where('segment_id', '=' , $segmentId)->first();
            $previousScheduleIndex = $lastScheduleData->request_index;
            $previousScheduleTime = $this->schedulesList[$previousScheduleIndex];
            $nextIndex = $previousScheduleIndex + 1;
            $nextScheduleTime = $this->schedulesList[$nextIndex] - $previousScheduleTime;
            $addSchedule = $this->addSchedule($segmentId,$nextIndex);
            if ($addSchedule) {
                return $nextScheduleTime;
            }
        }
    }

    /*
     * suppose If we have schedules created in the database for the five previous requests but with no data and then we launch the 6th
     * request which have some data then we'll have to delete all the schedules created for the given segment to again
     * start from 1 minute and so on.
     */
    public function deleteAllSchedules($segmentId)
    {
       if($this->table->where('segment_id','=',$segmentId)->count() > 0)
       {
           return $this->table->where('segment_id','=',$segmentId)->delete();
       }
       else
       {
           return true;
       }
    }

    public function addSchedule($segmentId,$requestIndex)
    {
        return $this->table->updateOrInsert(['segment_id' => $segmentId],['segment_id' => $segmentId,'request_index' => $requestIndex,'updated_at' => now(),'created_at' => now()]);
    }
}
