<?php 
namespace App\Controllers;
 
use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use App\Models\UserModel;


class UserDashboardWidgetController extends BaseController
{
    use ResponseTrait;

    function __construct(){
        $this->db = db_connect();
    }

    public function summary() {
        $user_id = $this->request->auth_user->id;
        $todays_work_summary = $this->request->getVar('today') == '1';
        $weekly_work_summary = $this->request->getVar('weekly') == '1';
        $leave_summary = $this->request->getVar('leave') == '1';
        $timesheet = $this->request->getVar('timesheet') == '1';

        $data = array();

        if($todays_work_summary) {
            $data['todays_work_summary'] = $this->todaysWorkSummary($user_id);
        }

        if($weekly_work_summary) {
            $weekday_from = $this->request->getVar('weekday_from');
            $weekday_to = $this->request->getVar('weekday_to');

            $data['weekly_work_summary'] = $this->weeklyWorkSummary($user_id, $weekday_from, $weekday_to);
        }

        if($leave_summary) {
            $data['leave_summary'] = $this->leaveSummary($user_id);
        }

        if($timesheet) {
            $data['timesheet_summary'] = $this->timesheetWorkHoursSummary($user_id);
        }

        $response = array(
            'success' => true,
            'data'    => $data,
        );

        return $this->respond($response);
    }

    private function todaysWorkSummary($user_id)
    {
        $sql_todays_work = "SELECT 
                    timestamp as start_time
                    , (SELECT MAX(timestamp) FROM user_activities ua1 WHERE ua1.user_id = ? AND ua1.timestamp >= DATE(UTC_TIMESTAMP())) as end_time
                    , (SELECT SUM(ua2.activity_slot) FROM user_activities ua2 WHERE ua2.user_id = ? AND ua2.timestamp >= DATE(UTC_TIMESTAMP())) as total_tracked_minutes
                    , (SELECT SUM(ua3.activity_per_slot) FROM user_activities ua3 WHERE ua3.user_id = ? AND ua3.timestamp >= DATE(UTC_TIMESTAMP())) as total_activity_minutes
                FROM user_activities ua WHERE 
                DATE(`timestamp`) = DATE(UTC_TIMESTAMP())
                AND ua.user_id = ?
                LIMIT 1";

        $result_todays_work = $this->db->query($sql_todays_work, array($user_id, $user_id, $user_id, $user_id));

        $result_todays_work = $result_todays_work->getResult('array');
        $start_time = null;
        $end_time = null;
        $total_tracked_minutes = null;
        $total_activity_minutes = null;
        $per_day_work_limit = 8;

        if (count($result_todays_work) > 0) {
            $start_time = $result_todays_work[0]['start_time'];
            $end_time = $result_todays_work[0]['end_time'];
            $total_tracked_minutes = $result_todays_work[0]['total_tracked_minutes'];
            $total_activity_minutes = $result_todays_work[0]['total_activity_minutes'];
        }

        $data = array(
            'start_time' => $start_time,
            'end_time' => $end_time,
            'total_tracked_minutes' => $total_tracked_minutes,
            'total_activity_minutes' => $total_activity_minutes,
            'per_day_work_limit' => $per_day_work_limit,
        );

        return $data;
    }

    private function weeklyWorkSummary($user_id, $weekday_from, $weekday_to)
    {
        $sql_weekly_work = "SELECT date(timestamp) day_date, SUM(ua.activity_slot) total_activity  FROM user_activities ua 
                WHERE timestamp >= ? AND timestamp <= ?
                AND user_id = ?
                GROUP BY date(timestamp)";

        $result_weekly = $this->db->query($sql_weekly_work, array($weekday_from,$weekday_to, $user_id));

        return $result_weekly->getResult('array');
    }

    private function leaveSummary($user_id)
    {
        $sql_leave_summary = "SELECT 
                                MAX(created_at) as last_leave_taken_at
                                , (SELECT COUNT(id) FROM user_leaves el2 WHERE el2.user_id = ? and el2.leave_type = 'absent') as total_absent
                                , (SELECT COUNT(id) FROM user_leaves el2 WHERE el2.user_id = ? and el2.leave_type = 'sick_leave' AND el2.status <> 'pending') as total_sick_leave
                                , (SELECT COUNT(id) FROM user_leaves el2 WHERE el2.user_id = ? and el2.leave_type = 'casual_leave' AND el2.status <> 'pending') as total_casual_leave
                                , (SELECT COUNT(id) FROM user_leaves el2 WHERE el2.user_id = ? and el2.leave_type <> 'absent' AND el2.status = 'pending') as total_pending
                            FROM user_leaves el 
                            WHERE el.user_id = ?
                            LIMIT 1";

        $result = $this->db->query($sql_leave_summary, array($user_id, $user_id, $user_id, $user_id, $user_id));
        $result = $result->getResult('array');

        if (count($result) > 0) {
            $result = $result[0];
        }

        return $result;
    }

    private function timesheetWorkHoursSummary($user_id)
    {
        // $data = array(
        //     'today' => '2/8',
        //     'this_week' => '35/40',
        //     'last_week' => '35/40',
        //     'this_month' => '80/160',
        // );

        $sql = "SELECT 
            SUM(CASE WHEN DATE(`timestamp`) = DATE(UTC_TIMESTAMP()) THEN activity_slot ELSE 0 END) AS today,
            SUM(CASE WHEN YEARWEEK(timestamp, 1) = YEARWEEK(DATE(UTC_TIMESTAMP()), 1) THEN activity_slot ELSE 0 END) AS this_week,
            SUM(CASE WHEN YEARWEEK(timestamp, 1) = YEARWEEK(DATE(UTC_TIMESTAMP()), 1) - 1 THEN activity_slot ELSE 0 END) AS last_week,
            SUM(CASE WHEN timestamp >= UTC_TIMESTAMP() - INTERVAL 1 MONTH THEN activity_slot ELSE 0 END) AS this_month
        FROM 
            user_activities
            WHERE user_id = ? AND timestamp >= UTC_TIMESTAMP() - INTERVAL 1 MONTH;";

        $result = $this->db->query($sql, array($user_id));

        $data = array(
            'success' => true,
            'data' => $result->getResult('array'),
        );
        return $data;
    }

    public function userActivityByDate()
    {
        $user_id = $this->request->auth_user->id;
        $date = $this->request->getVar('date');

        if (!$date) {
            $date = "DATE(UTC_TIMESTAMP())";
        }

        $sql_activity_logs = "SELECT 
                at2.name as activity_name
                , ua.memo
                , ua.timestamp as created_at
                , ua.activity_slot
                , ua.activity_per_slot 
                , ua.mouse_click
                , ua.mouse_scroll 
                , ua.keyboard_activities 
                , ua.screenshot_link
            FROM user_activities ua
            INNER JOIN activity_types at2 ON at2.id  = ua.activity_id             
            WHERE ua.user_id = ?
            AND DATE(ua.timestamp) = ?
            ORDER BY ua.id ASC;
        ";

        $result = $this->db->query($sql_activity_logs, array($user_id, $date));

        $data = array(
            'success' => true,
            'data' => $result->getResult('array'),
        );

        return $this->respond($data);
    }

    public function userLastActivityLogs()
    {
        $user_id = $this->request->auth_user->id;
        $sql_activity_logs = "SELECT 
                at2.name as activity_name
                , ua.memo
                , ua.timestamp as created_at
                , ua.activity_slot
            FROM user_activities ua
            INNER JOIN activity_types at2 ON at2.id  = ua.activity_id             
            WHERE ua.user_id = ?
            AND DATE(ua.timestamp) >= DATE(UTC_TIMESTAMP()); -- change the date to last 24 hrs
        ";

        $result = $this->db->query($sql_activity_logs, array($user_id));

        $data = array(
            'success' => true,
            'data' => $result->getResult('array'),
        );

        return $this->respond($data);
    }
}
