"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { hasPermission } from "@/lib/types";
import { useSession } from "@/components/workspace-shell";

type Session = { id: number; name: string };
type Section = { id: number; name: string };
type SchoolClass = { id: number; name: string; academic_session_id: number; sections: Section[] };
type Subject = { id: number; name: string; academic_session_id: number; classes: { id: number; name: string }[] };
type Employee = { id: number; employee_id: string; first_name: string; last_name: string | null; is_teaching_staff: boolean; status: string };
type Slot = {
  id: number; weekday: number; period_number: number; starts_at: string; ends_at: string; room_number: string | null;
  academic_session: Session; school_class: { name: string }; section: Section;
  subject: { name: string }; employee: { first_name: string; last_name: string | null; employee_id: string };
};
const weekdays = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
const initialForm = {
  academic_session_id: "", school_class_id: "", section_id: "", subject_id: "", employee_id: "",
  weekday: "1", period_number: "1", starts_at: "09:00", ends_at: "09:45", room_number: "",
};

export default function TimetablePage() {
  const { user } = useSession();
  const [sessions, setSessions] = useState<Session[]>([]);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [slots, setSlots] = useState<Slot[]>([]);
  const [form, setForm] = useState(initialForm);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const canCreate = hasPermission(user, "timetable.create");
  const canDelete = hasPermission(user, "timetable.delete");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const [sessionResult, classResult, subjectResult, employeeResult, slotResult] = await Promise.all([
        apiRequest<{ data: Session[] }>("/academic-sessions"),
        apiRequest<{ data: SchoolClass[] }>("/classes"),
        apiRequest<{ data: Subject[] }>("/subjects"),
        apiRequest<{ data: Employee[] }>("/employees?per_page=100"),
        apiRequest<{ data: Slot[] }>("/timetable"),
      ]);
      setSessions(sessionResult.data);
      setClasses(classResult.data);
      setSubjects(subjectResult.data);
      setEmployees(employeeResult.data.filter((employee) => employee.is_teaching_staff && employee.status === "active"));
      setSlots(slotResult.data);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void Promise.resolve().then(load); }, [load]);
  const availableClasses = classes.filter((item) => item.academic_session_id === Number(form.academic_session_id));
  const availableSubjects = subjects.filter((item) => item.academic_session_id === Number(form.academic_session_id)
    && item.classes.some((schoolClass) => schoolClass.id === Number(form.school_class_id)));
  const selectedClass = availableClasses.find((item) => item.id === Number(form.school_class_id));

  async function createSlot(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await apiRequest("/timetable", {
        method: "POST",
        body: {
          ...form,
          academic_session_id: Number(form.academic_session_id),
          school_class_id: Number(form.school_class_id),
          section_id: Number(form.section_id),
          subject_id: Number(form.subject_id),
          employee_id: Number(form.employee_id),
          weekday: Number(form.weekday),
          period_number: Number(form.period_number),
          room_number: form.room_number || null,
        },
      });
      setNotice("Timetable period created.");
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function remove(slot: Slot) {
    if (!window.confirm(`Remove ${weekdays[slot.weekday - 1]} period ${slot.period_number}?`)) return;
    try {
      await apiRequest(`/timetable/${slot.id}`, { method: "DELETE" });
      setNotice("Timetable period removed.");
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }

  return (
    <div className="page-stack">
      <header className="page-title-row">
        <div><div className="eyebrow">ACADEMIC MANAGEMENT</div><h1>Timetable<span className="heading-period">.</span></h1><p>Build section schedules with subject, teacher and time-conflict checks.</p></div>
        <button className="button button-secondary" disabled={loading} onClick={() => void load()}>↻ Refresh</button>
      </header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      {canCreate && <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">NEW PERIOD</div><h2>Add timetable slot</h2></div></div>
        <form className="compact-form" onSubmit={createSlot}>
          <div className="form-two-col">
            <label className="field-label">Academic session<select value={form.academic_session_id} onChange={(event) => setForm({ ...form, academic_session_id: event.target.value, school_class_id: "", section_id: "", subject_id: "" })} required><option value="">Select session</option>{sessions.map((session) => <option key={session.id} value={session.id}>{session.name}</option>)}</select></label>
            <label className="field-label">Class<select value={form.school_class_id} onChange={(event) => setForm({ ...form, school_class_id: event.target.value, section_id: "", subject_id: "" })} required><option value="">Select class</option>{availableClasses.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            <label className="field-label">Section<select value={form.section_id} onChange={(event) => setForm({ ...form, section_id: event.target.value })} required><option value="">Select section</option>{selectedClass?.sections.map((section) => <option key={section.id} value={section.id}>{section.name}</option>)}</select></label>
            <label className="field-label">Subject<select value={form.subject_id} onChange={(event) => setForm({ ...form, subject_id: event.target.value })} required><option value="">Select subject</option>{availableSubjects.map((subject) => <option key={subject.id} value={subject.id}>{subject.name}</option>)}</select></label>
            <label className="field-label">Teacher<select value={form.employee_id} onChange={(event) => setForm({ ...form, employee_id: event.target.value })} required><option value="">Select teacher</option>{employees.map((employee) => <option key={employee.id} value={employee.id}>{employee.first_name} {employee.last_name} · {employee.employee_id}</option>)}</select></label>
            <label className="field-label">Weekday<select value={form.weekday} onChange={(event) => setForm({ ...form, weekday: event.target.value })}>{weekdays.map((day, index) => <option key={day} value={index + 1}>{day}</option>)}</select></label>
            <label className="field-label">Period number<input type="number" min="1" max="20" value={form.period_number} onChange={(event) => setForm({ ...form, period_number: event.target.value })} required /></label>
            <label className="field-label">Room<input value={form.room_number} onChange={(event) => setForm({ ...form, room_number: event.target.value })} maxLength={50} /></label>
            <label className="field-label">Starts at<input type="time" value={form.starts_at} onChange={(event) => setForm({ ...form, starts_at: event.target.value })} required /></label>
            <label className="field-label">Ends at<input type="time" value={form.ends_at} onChange={(event) => setForm({ ...form, ends_at: event.target.value })} required /></label>
          </div>
          <button className="button button-primary" disabled={saving}>{saving ? "Saving…" : "Add timetable period"}</button>
        </form>
      </section>}
      <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">WEEKLY SCHEDULE</div><h2>Timetable periods</h2></div><span className="count-pill">{slots.length}</span></div>
        <div className="table-scroll"><table className="data-table"><thead><tr><th>DAY / PERIOD</th><th>CLASS / SECTION</th><th>SUBJECT</th><th>TIME / ROOM</th><th>TEACHER</th><th>ACTION</th></tr></thead><tbody>
          {loading ? <tr><td className="table-empty" colSpan={6}>Loading timetable…</td></tr> : slots.length === 0 ? <tr><td className="table-empty" colSpan={6}>No timetable periods have been added.</td></tr> : slots.map((slot) => <tr key={slot.id}>
            <td>{weekdays[slot.weekday - 1]}<small>Period {slot.period_number}</small></td>
            <td>{slot.school_class.name} / {slot.section.name}<small>{slot.academic_session.name}</small></td>
            <td>{slot.subject.name}</td><td>{slot.starts_at.slice(0, 5)}–{slot.ends_at.slice(0, 5)}<small>{slot.room_number ?? "No room"}</small></td>
            <td>{slot.employee.first_name} {slot.employee.last_name}<small>{slot.employee.employee_id}</small></td>
            <td>{canDelete && <button className="text-link" onClick={() => void remove(slot)}>Remove</button>}</td>
          </tr>)}
        </tbody></table></div>
      </section>
    </div>
  );
}
