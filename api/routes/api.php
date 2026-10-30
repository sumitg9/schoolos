<?php

use App\Http\Controllers\Api\AcademicManagementController;
use App\Http\Controllers\Api\AcademicSessionController;
use App\Http\Controllers\Api\AccessControlController;
use App\Http\Controllers\Api\AdvancedOperationsController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\FeeController;
use App\Http\Controllers\Api\HrmsController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OperationsAdministrationController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\RazorpayWebhookController;
use App\Http\Controllers\Api\SchoolClassController;
use App\Http\Controllers\Api\SchoolController;
use App\Http\Controllers\Api\SchoolSettingController;
use App\Http\Controllers\Api\SectionController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\TeacherAssignmentController;
use App\Http\Middleware\ResolveSchoolContext;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:login')->post('/login', [AuthController::class, 'login']);
Route::middleware('throttle:login')->post('/portal/register', [PortalController::class, 'register']);
Route::middleware('throttle:30,1')->post('/webhooks/razorpay', RazorpayWebhookController::class);

Route::middleware(['auth:sanctum', ResolveSchoolContext::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::middleware('permission:schools.view')->get('/schools', [SchoolController::class, 'index']);
    Route::middleware('permission:schools.create')->post('/schools', [SchoolController::class, 'store']);
    Route::post('/schools/{school}/switch', [SchoolController::class, 'switch']);

    Route::get('/portal/children', [PortalController::class, 'children']);
    Route::get('/portal/students/{student}', [PortalController::class, 'student']);
    Route::get('/portal/receipts/{payment}', [PortalController::class, 'receipt']);
    Route::middleware('permission:portal_accounts.create')->post('/portal/activation-codes', [PortalController::class, 'activationCode']);
    Route::middleware('permission:attendance.view')->get('/attendance', [AttendanceController::class, 'roster']);
    Route::middleware('permission:attendance.create')->put('/attendance', [AttendanceController::class, 'save']);
    Route::middleware('permission:fees.view')->get('/fees', [FeeController::class, 'index']);
    Route::middleware('permission:fees.view')->get('/fees/targets', [FeeController::class, 'targets']);
    Route::middleware('permission:fees.view')->get('/fees/reports', [FeeController::class, 'reports']);
    Route::middleware('permission:fees.view')->get('/fees/receipts/{payment}', [FeeController::class, 'receipt']);
    Route::middleware('permission:fees.create')->post('/fees/structures', [FeeController::class, 'createStructure']);
    Route::middleware('permission:fees.create')->post('/fees/structures/{structure}/invoices', [FeeController::class, 'issueInvoices']);
    Route::middleware('permission:fees.edit')->patch('/fees/invoices/{invoice}/concession', [FeeController::class, 'applyConcession']);
    Route::middleware('permission:fees.create')->post('/fees/charges', [FeeController::class, 'createCharge']);
    Route::middleware('permission:fee_payments.create')->post('/fees/payments', [FeeController::class, 'createPayment']);
    Route::middleware('permission:fees.view')->get('/fees/payment-orders', [FeeController::class, 'paymentOrders']);
    Route::post('/portal/payments/orders', [FeeController::class, 'createCheckoutOrder']);

    Route::middleware('permission:notifications.view')->get('/notifications', [NotificationController::class, 'index']);
    Route::middleware('permission:notifications.create')->post('/notifications', [NotificationController::class, 'send']);
    Route::middleware('permission:library.view')->get('/library', [AdvancedOperationsController::class, 'library']);
    Route::middleware('permission:library.create')->post('/library/books', [AdvancedOperationsController::class, 'createBook']);
    Route::middleware('permission:library.create')->post('/library/loans', [AdvancedOperationsController::class, 'issueBook']);
    Route::middleware('permission:library.edit')->post('/library/loans/{loan}/return', [AdvancedOperationsController::class, 'returnBook']);
    Route::middleware('permission:transport.view')->get('/transport', [AdvancedOperationsController::class, 'transport']);
    Route::middleware('permission:transport.create')->post('/transport/vehicles', [AdvancedOperationsController::class, 'createVehicle']);
    Route::middleware('permission:transport.edit')->patch('/transport/vehicles/{vehicle}', [AdvancedOperationsController::class, 'updateVehicle']);
    Route::middleware('permission:transport.create')->post('/transport/routes', [AdvancedOperationsController::class, 'createRoute']);
    Route::middleware('permission:transport.edit')->patch('/transport/routes/{route}', [AdvancedOperationsController::class, 'updateRoute']);
    Route::middleware('permission:transport.create')->post('/transport/assignments', [AdvancedOperationsController::class, 'assignTransport']);
    Route::middleware('permission:transport.edit')->post('/transport/assignments/{assignment}/end', [AdvancedOperationsController::class, 'endTransportAssignment']);
    Route::middleware('permission:payroll.view')->get('/payroll', [HrmsController::class, 'payroll']);
    Route::middleware('permission:payroll.create')->post('/payroll/structures', [HrmsController::class, 'createSalaryStructure']);
    Route::middleware('permission:payroll.create')->post('/payroll', [HrmsController::class, 'createPayroll']);
    Route::middleware('permission:payroll.view')->get('/payroll/{record}/slip', [HrmsController::class, 'salarySlip']);
    Route::middleware('permission:payroll.approve')->post('/payroll/{record}/approve', [HrmsController::class, 'approvePayroll']);
    Route::middleware('permission:payroll.pay')->post('/payroll/{record}/pay', [HrmsController::class, 'payPayroll']);
    Route::middleware('permission:leave.view')->get('/leave', [HrmsController::class, 'leave']);
    Route::middleware('permission:leave.create')->post('/leave', [HrmsController::class, 'createLeave']);
    Route::middleware('permission:leave.approve')->post('/leave/{leave}/review', [HrmsController::class, 'reviewLeave']);
    Route::middleware('permission:inventory.view')->get('/inventory', [AdvancedOperationsController::class, 'inventory']);
    Route::middleware('permission:inventory.create')->post('/inventory', [AdvancedOperationsController::class, 'createInventoryItem']);
    Route::middleware('permission:inventory.edit')->post('/inventory/{item}/transactions', [AdvancedOperationsController::class, 'adjustInventory']);
    Route::middleware('permission:expenses.view')->get('/expenses', [OperationsAdministrationController::class, 'expenses']);
    Route::middleware('permission:expenses.create')->post('/expenses', [OperationsAdministrationController::class, 'createExpense']);
    Route::middleware('permission:expenses.approve')->post('/expenses/{expense}/review', [OperationsAdministrationController::class, 'reviewExpense']);
    Route::middleware('permission:expenses.pay')->post('/expenses/{expense}/pay', [OperationsAdministrationController::class, 'payExpense']);
    Route::middleware('permission:assets.view')->get('/assets', [OperationsAdministrationController::class, 'assets']);
    Route::middleware('permission:assets.create')->post('/assets', [OperationsAdministrationController::class, 'createAsset']);
    Route::middleware('permission:assets.edit')->post('/assets/{asset}/lifecycle', [OperationsAdministrationController::class, 'updateAsset']);
    Route::middleware('permission:events.view')->get('/events', [OperationsAdministrationController::class, 'events']);
    Route::middleware('permission:events.create')->post('/events', [OperationsAdministrationController::class, 'createEvent']);
    Route::middleware('permission:events.edit')->patch('/events/{event}', [OperationsAdministrationController::class, 'updateEvent']);

    Route::middleware('permission:dashboard.view')->get('/dashboard/summary', DashboardController::class);
    Route::middleware('permission:audit_logs.view')->get('/audit-logs', [AuditLogController::class, 'index']);

    Route::middleware('permission:roles.view')->get('/access/roles', [AccessControlController::class, 'roles']);
    Route::middleware('permission:roles.edit')->put('/access/roles/{role}/permissions', [AccessControlController::class, 'updateRolePermissions']);
    Route::middleware('permission:users.view')->get('/access/users', [AccessControlController::class, 'users']);
    Route::middleware('permission:users.create')->post('/access/users', [AccessControlController::class, 'createUser']);
    Route::middleware('permission:users.edit')->put('/access/users/{user}/roles', [AccessControlController::class, 'updateUserRoles']);
    Route::middleware('permission:users.edit')->put('/access/users/{user}/status', [AccessControlController::class, 'updateUserStatus']);

    Route::middleware('permission:school_settings.view')->get('/settings/school', [SchoolSettingController::class, 'show']);
    Route::middleware('permission:school_settings.edit')->put('/settings/school', [SchoolSettingController::class, 'update']);

    Route::middleware('permission:academic_sessions.view')->get('/academic-sessions', [AcademicSessionController::class, 'index']);
    Route::middleware('permission:academic_sessions.create')->post('/academic-sessions', [AcademicSessionController::class, 'store']);
    Route::middleware('permission:academic_sessions.edit')->put('/academic-sessions/{academicSession}', [AcademicSessionController::class, 'update']);
    Route::middleware('permission:academic_sessions.delete')->delete('/academic-sessions/{academicSession}', [AcademicSessionController::class, 'destroy']);

    Route::middleware('permission:classes.view')->get('/classes', [SchoolClassController::class, 'index']);
    Route::middleware('permission:classes.create')->post('/classes', [SchoolClassController::class, 'store']);
    Route::middleware('permission:classes.edit')->put('/classes/{schoolClass}', [SchoolClassController::class, 'update']);
    Route::middleware('permission:classes.delete')->delete('/classes/{schoolClass}', [SchoolClassController::class, 'destroy']);

    Route::middleware('permission:classes.create')->post('/classes/{schoolClass}/sections', [SectionController::class, 'store']);
    Route::middleware('permission:classes.edit')->put('/sections/{section}', [SectionController::class, 'update']);
    Route::middleware('permission:classes.delete')->delete('/sections/{section}', [SectionController::class, 'destroy']);

    Route::middleware('permission:subjects.view')->get('/subjects', [SubjectController::class, 'index']);
    Route::middleware('permission:subjects.create')->post('/subjects', [SubjectController::class, 'store']);
    Route::middleware('permission:subjects.edit')->put('/subjects/{subject}', [SubjectController::class, 'update']);
    Route::middleware('permission:subjects.delete')->delete('/subjects/{subject}', [SubjectController::class, 'destroy']);

    Route::middleware('permission:exams.view')->get('/exams', [AcademicManagementController::class, 'exams']);
    Route::middleware('permission:exams.create')->post('/exams', [AcademicManagementController::class, 'createExam']);
    Route::middleware('permission:exams.approve')->post('/exams/{exam}/publish', [AcademicManagementController::class, 'publishExam']);
    Route::middleware('permission:marks.approve')->post('/exams/{exam}/approve-results', [AcademicManagementController::class, 'approveResults']);
    Route::middleware('permission:marks.publish')->post('/exams/{exam}/publish-results', [AcademicManagementController::class, 'publishResults']);
    Route::middleware('permission:exams.delete')->delete('/exams/{exam}', [AcademicManagementController::class, 'deleteExam']);
    Route::middleware('permission:marks.view')->get('/exams/{exam}/marks', [AcademicManagementController::class, 'examMarks']);
    Route::middleware('permission:marks.edit')->put('/exams/{exam}/marks', [AcademicManagementController::class, 'saveExamMarks']);
    Route::middleware('permission:marks.print')->get('/exams/{exam}/report-cards', [AcademicManagementController::class, 'reportCards']);

    Route::middleware('permission:homework.view')->get('/homework', [AcademicManagementController::class, 'homework']);
    Route::middleware('permission:homework.create')->post('/homework', [AcademicManagementController::class, 'createHomework']);
    Route::middleware('permission:homework.delete')->delete('/homework/{homework}', [AcademicManagementController::class, 'deleteHomework']);

    Route::middleware('permission:timetable.view')->get('/timetable', [AcademicManagementController::class, 'timetable']);
    Route::middleware('permission:timetable.create')->post('/timetable', [AcademicManagementController::class, 'createTimetableSlot']);
    Route::middleware('permission:timetable.delete')->delete('/timetable/{slot}', [AcademicManagementController::class, 'deleteTimetableSlot']);

    Route::middleware('permission:notices.view')->get('/notices', [AcademicManagementController::class, 'notices']);
    Route::middleware('permission:notices.create')->post('/notices', [AcademicManagementController::class, 'createNotice']);
    Route::middleware('permission:notices.publish')->post('/notices/{notice}/publish', [AcademicManagementController::class, 'publishNotice']);
    Route::middleware('permission:notices.delete')->delete('/notices/{notice}', [AcademicManagementController::class, 'deleteNotice']);

    Route::middleware(['permission:students.view', 'permission:students.export'])->get('/students/export', [StudentController::class, 'export']);
    Route::middleware(['permission:students.view', 'permission:students.import'])->get('/students/import-template', [StudentController::class, 'importTemplate']);
    Route::middleware('permission:students.import')->post('/students/import', [StudentController::class, 'import']);
    Route::middleware('permission:students.view')->get('/students', [StudentController::class, 'index']);
    Route::middleware('permission:students.create')->post('/students', [StudentController::class, 'store']);
    Route::middleware('permission:students.view')->get('/students/{student}', [StudentController::class, 'show']);
    Route::middleware('permission:students.edit')->put('/students/{student}', [StudentController::class, 'update']);
    Route::middleware('permission:students.edit')->put('/students/{student}/guardians', [StudentController::class, 'updateGuardians']);
    Route::middleware('permission:students.medical_records.view')->get('/students/{student}/medical-record', [StudentController::class, 'showMedicalRecord']);
    Route::middleware('permission:students.medical_records.edit')->put('/students/{student}/medical-record', [StudentController::class, 'updateMedicalRecord']);
    Route::middleware('permission:students.approve')->post('/students/{student}/promote', [StudentController::class, 'promote']);
    Route::middleware('permission:students.edit')->post('/students/{student}/transfer', [StudentController::class, 'transfer']);
    Route::middleware('permission:students.edit')->delete('/students/{student}', [StudentController::class, 'destroy']);
    Route::middleware('permission:students.upload')->post('/students/{student}/documents', [DocumentController::class, 'uploadStudent']);
    Route::middleware(['permission:students.view', 'permission:students.upload'])->get('/student-documents/{document}/download', [DocumentController::class, 'downloadStudent']);

    Route::middleware(['permission:employees.view', 'permission:employees.export'])->get('/employees/export', [EmployeeController::class, 'export']);
    Route::middleware('permission:employees.import')->post('/employees/import', [EmployeeController::class, 'import']);
    Route::middleware(['permission:employees.view', 'permission:employees.import'])->get('/employees/import-template', [EmployeeController::class, 'importTemplate']);
    Route::middleware('permission:employees.view')->get('/employees', [EmployeeController::class, 'index']);
    Route::middleware('permission:employees.create')->post('/employees', [EmployeeController::class, 'store']);
    Route::middleware('permission:employees.view')->get('/employees/{employee}', [EmployeeController::class, 'show']);
    Route::middleware('permission:employees.edit')->put('/employees/{employee}', [EmployeeController::class, 'update']);
    Route::middleware('permission:employees.edit')->delete('/employees/{employee}', [EmployeeController::class, 'destroy']);
    Route::middleware('permission:employees.upload')->post('/employees/{employee}/documents', [DocumentController::class, 'uploadEmployee']);
    Route::middleware(['permission:employees.view', 'permission:employees.upload'])->get('/employee-documents/{document}/download', [DocumentController::class, 'downloadEmployee']);

    Route::middleware('permission:teacher_assignments.view')->get('/teacher-assignments', [TeacherAssignmentController::class, 'index']);
    Route::middleware('permission:teacher_assignments.create')->post('/teacher-assignments', [TeacherAssignmentController::class, 'store']);
    Route::middleware('permission:teacher_assignments.delete')->delete('/teacher-assignments/{teacherAssignment}', [TeacherAssignmentController::class, 'destroy']);
});
