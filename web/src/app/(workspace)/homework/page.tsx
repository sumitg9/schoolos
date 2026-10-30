"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { formatDate } from "@/lib/date";
import { hasPermission } from "@/lib/types";
import { useSession } from "@/components/workspace-shell";

type Session = { id: number; name: string };
type Section = { id: number; name: string };
type SchoolClass = { id: number; name: string; academic_session_id: number; sections: Section[] };
type Subject = { id: number; name: string; academic_session_id: number; classes: { id: number; name: string }[] };
type Homework = {
  id: number; title: string; description: string; assigned_on: string; due_on: string;
  academic_session: Session; school_class: { name: string }; section: Section | null;
  subject: { name: string }; employee: { first_name: string; last_name: string | null } | null;
};

const initialForm = {
  academic_session_id: "", school_class_id: "", section_id: "", subject_id: "",
  title: "", description: "", assigned_on: new Date().toISOString().slice(0, 10), due_on: "",
};

export default function HomeworkPage() {
  const { user } = useSession();
  const [sessions, setSessions] = useState<Session[]>([]);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [homework, setHomework] = useState<Homework[]>([]);
  const [form, setForm] = useState(initialForm);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const canCreate = hasPermission(user, "homework.create");
  const canDelete = hasPermission(user, "homework.delete");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const [sessionResult, classResult, subjectResult, homeworkResult] = await Promise.all([
        apiRequest<{ data: Session[] }>("/academic-sessions"),
        apiRequest<{ data: SchoolClass[] }>("/classes"),
        apiRequest<{ data: Subject[] }>("/subjects"),
        apiRequest<{ data: { data: Homework[] } }>("/homework?per_page=100"),
      ]);
      setSessions(sessionResult.data);
      setClasses(classResult.data);
      setSubjects(subjectResult.data);
      setHomework(homeworkResult.data.data);
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

  async function createHomework(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await apiRequest("/homework", {
        method: "POST",
        body: {
          academic_session_id: Number(form.academic_session_id),
          school_class_id: Number(form.school_class_id),
          section_id: form.section_id ? Number(form.section_id) : null,
          subject_id: Number(form.subject_id),
          title: form.title,
          description: form.description,
          assigned_on: form.assigned_on,
          due_on: form.due_on,
        },
      });
      setNotice("Homework assignment created.");
      setForm(initialForm);
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function remove(item: Homework) {
    if (!window.confirm(`Delete "${item.title}"?`)) return;
    try {
      await apiRequest(`/homework/${item.id}`, { method: "DELETE" });
      setNotice("Homework assignment deleted.");
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }

  return (
    <div className="page-stack">
      <header className="page-title-row">
        <div><div className="eyebrow">CLASSROOM WORK</div><h1>Homework & assignments<span className="heading-period">.</span></h1><p>Assign dated subject work to a class or section and keep due dates visible.</p></div>
        <button className="button button-secondary" disabled={loading} onClick={() => void load()}>↻ Refresh</button>
      </header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      {canCreate && <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">NEW ASSIGNMENT</div><h2>Post homework</h2></div></div>
        <form className="compact-form" onSubmit={createHomework}>
          <div className="form-two-col">
            <label className="field-label">Academic session<select value={form.academic_session_id} onChange={(event) => setForm({ ...form, academic_session_id: event.target.value, school_class_id: "", section_id: "", subject_id: "" })} required><option value="">Select session</option>{sessions.map((session) => <option key={session.id} value={session.id}>{session.name}</option>)}</select></label>
            <label className="field-label">Class<select value={form.school_class_id} onChange={(event) => setForm({ ...form, school_class_id: event.target.value, section_id: "", subject_id: "" })} required><option value="">Select class</option>{availableClasses.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
            <label className="field-label">Section<select value={form.section_id} onChange={(event) => setForm({ ...form, section_id: event.target.value })}><option value="">All sections</option>{selectedClass?.sections.map((section) => <option key={section.id} value={section.id}>{section.name}</option>)}</select></label>
            <label className="field-label">Subject<select value={form.subject_id} onChange={(event) => setForm({ ...form, subject_id: event.target.value })} required><option value="">Select subject</option>{availableSubjects.map((subject) => <option key={subject.id} value={subject.id}>{subject.name}</option>)}</select></label>
            <label className="field-label">Title<input value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} required maxLength={180} /></label>
            <label className="field-label">Assigned on<input type="date" value={form.assigned_on} onChange={(event) => setForm({ ...form, assigned_on: event.target.value })} required /></label>
            <label className="field-label">Due date<input type="date" value={form.due_on} min={form.assigned_on} onChange={(event) => setForm({ ...form, due_on: event.target.value })} required /></label>
          </div>
          <label className="field-label">Instructions / description<textarea rows={4} value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} required maxLength={10000} /></label>
          <button className="button button-primary" disabled={saving}>{saving ? "Saving…" : "Post assignment"}</button>
        </form>
      </section>}
      <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">ASSIGNMENT REGISTER</div><h2>Homework</h2></div><span className="count-pill">{homework.length}</span></div>
        <div className="table-scroll"><table className="data-table"><thead><tr><th>ASSIGNMENT</th><th>CLASS / SECTION</th><th>SUBJECT</th><th>DATES</th><th>TEACHER</th><th>ACTION</th></tr></thead><tbody>
          {loading ? <tr><td className="table-empty" colSpan={6}>Loading assignments…</td></tr> : homework.length === 0 ? <tr><td className="table-empty" colSpan={6}>No homework has been posted.</td></tr> : homework.map((item) => <tr key={item.id}>
            <td><strong>{item.title}</strong><small>{item.description}</small></td>
            <td>{item.school_class.name}<small>{item.section?.name ?? "All sections"} · {item.academic_session.name}</small></td>
            <td>{item.subject.name}</td><td>Assigned {formatDate(item.assigned_on)}<small>Due {formatDate(item.due_on)}</small></td>
            <td>{item.employee ? `${item.employee.first_name} ${item.employee.last_name ?? ""}` : "School staff"}</td>
            <td>{canDelete && <button className="text-link" onClick={() => void remove(item)}>Delete</button>}</td>
          </tr>)}
        </tbody></table></div>
      </section>
    </div>
  );
}
