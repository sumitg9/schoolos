"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";

type Session = { id: number; name: string };
type Section = { id: number; name: string };
type SchoolClass = { id: number; name: string; academic_session_id: number; sections: Section[] };
type AttendanceEntry = { enrollment_id: number; roll_number: string | null; student: { admission_number: string; first_name: string; last_name: string | null }; status: string | null; remarks: string | null };

const currentDate = new Date();
const today = `${currentDate.getFullYear()}-${String(currentDate.getMonth() + 1).padStart(2, "0")}-${String(currentDate.getDate()).padStart(2, "0")}`;

export default function AttendancePage() {
  const [sessions, setSessions] = useState<Session[]>([]);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [sessionId, setSessionId] = useState("");
  const [classId, setClassId] = useState("");
  const [sectionId, setSectionId] = useState("");
  const [date, setDate] = useState(today);
  const [students, setStudents] = useState<AttendanceEntry[]>([]);
  const [statuses, setStatuses] = useState<Record<number, string>>({});
  const [remarks, setRemarks] = useState<Record<number, string>>({});
  const [loading, setLoading] = useState(true);
  const [rosterKey, setRosterKey] = useState("");
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const selectedClass = classes.find((item) => item.id === Number(classId));
  const sections = selectedClass?.sections ?? [];

  const loadSetup = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const [sessionResult, classResult] = await Promise.all([
        apiRequest<{ data: Session[] }>("/academic-sessions"),
        apiRequest<{ data: SchoolClass[] }>("/classes"),
      ]);
      setSessions(sessionResult.data);
      setClasses(classResult.data);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void Promise.resolve().then(loadSetup); }, [loadSetup]);

  const filterKey = sessionId && classId && sectionId && date ? `${sessionId}:${classId}:${sectionId}:${date}` : "";
  const visibleStudents = filterKey === rosterKey ? students : [];
  useEffect(() => {
    if (!sessionId || !classId || !sectionId || !date) {
      return;
    }
    let active = true;
    const query = new URLSearchParams({ academic_session_id: sessionId, school_class_id: classId, section_id: sectionId, attendance_date: date });
    apiRequest<{ data: AttendanceEntry[] }>(`/attendance?${query}`)
      .then(({ data }) => {
        if (!active) return;
        setError("");
        setStudents(data);
        setRosterKey(`${sessionId}:${classId}:${sectionId}:${date}`);
        setStatuses(Object.fromEntries(data.filter((entry) => entry.status).map((entry) => [entry.enrollment_id, entry.status as string])));
        setRemarks(Object.fromEntries(data.filter((entry) => entry.remarks).map((entry) => [entry.enrollment_id, entry.remarks as string])));
      })
      .catch((reason: unknown) => { if (active) setError(errorMessage(reason)); });
    return () => { active = false; };
  }, [sessionId, classId, sectionId, date]);

  async function saveAttendance(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (visibleStudents.some((student) => !statuses[student.enrollment_id])) {
      setError("Choose an attendance status for every student before saving.");
      return;
    }
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await apiRequest("/attendance", {
        method: "PUT",
        body: {
          academic_session_id: Number(sessionId),
          school_class_id: Number(classId),
          section_id: Number(sectionId),
          attendance_date: date,
          entries: visibleStudents.map((student) => ({
            student_enrollment_id: student.enrollment_id,
            status: statuses[student.enrollment_id],
            remarks: remarks[student.enrollment_id] || null,
          })),
        },
      });
      setNotice(`Attendance saved for ${visibleStudents.length} student(s).`);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="page-stack">
      <header className="page-title-row"><div><div className="eyebrow">DAILY CLASS RECORDS</div><h1>Attendance<span className="heading-period">.</span></h1><p>Record attendance by class and section. Existing records can be corrected by saving again.</p></div><button className="button button-secondary" disabled={loading} onClick={() => void loadSetup()}>↻ Refresh</button></header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      <section className="surface-card">
        <form className="compact-form" onSubmit={saveAttendance}>
          <div className="form-two-col">
            <label className="field-label">Academic session<select required value={sessionId} onChange={(event) => { setSessionId(event.target.value); setClassId(""); setSectionId(""); }}><option value="">Select session</option>{sessions.map((session) => <option key={session.id} value={session.id}>{session.name}</option>)}</select></label>
            <label className="field-label">Class<select required value={classId} onChange={(event) => { setClassId(event.target.value); setSectionId(""); }}><option value="">Select class</option>{classes.filter((item) => item.academic_session_id === Number(sessionId)).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            <label className="field-label">Section<select required value={sectionId} onChange={(event) => setSectionId(event.target.value)}><option value="">Select section</option>{sections.map((section) => <option key={section.id} value={section.id}>{section.name}</option>)}</select></label>
            <label className="field-label">Attendance date<input type="date" required value={date} onChange={(event) => setDate(event.target.value)} /></label>
          </div>
          {visibleStudents.length > 0 && <>
            <div className="data-table-wrap"><table className="data-table"><thead><tr><th>Roll</th><th>Student</th><th>Status</th><th>Remarks</th></tr></thead><tbody>
              {visibleStudents.map((student) => <tr key={student.enrollment_id}>
                <td>{student.roll_number ?? "—"}</td><td>{student.student.first_name} {student.student.last_name ?? ""}<small>{student.student.admission_number}</small></td>
                <td><select aria-label={`Attendance status for ${student.student.first_name}`} required value={statuses[student.enrollment_id] ?? ""} onChange={(event) => setStatuses({ ...statuses, [student.enrollment_id]: event.target.value })}><option value="">Select status</option><option value="present">Present</option><option value="absent">Absent</option><option value="late">Late</option><option value="excused">Excused</option></select></td>
                <td><input aria-label={`Remarks for ${student.student.first_name}`} value={remarks[student.enrollment_id] ?? ""} onChange={(event) => setRemarks({ ...remarks, [student.enrollment_id]: event.target.value })} /></td>
              </tr>)}
            </tbody></table></div>
            <button className="button button-primary" disabled={saving}>{saving ? "Saving…" : "Save attendance"}</button>
          </>}
          {!visibleStudents.length && filterKey && filterKey === rosterKey && <p className="muted-copy">No active students are enrolled in the selected section.</p>}
          {!visibleStudents.length && filterKey && filterKey !== rosterKey && !error && <p className="muted-copy">Loading the selected class roster…</p>}
        </form>
      </section>
    </div>
  );
}
