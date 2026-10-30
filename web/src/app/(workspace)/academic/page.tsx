"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { formatDate } from "@/lib/date";
import { hasPermission } from "@/lib/types";
import { useSession } from "@/components/workspace-shell";

type AcademicSession = {
  id: number;
  name: string;
  starts_on: string;
  ends_on: string;
  is_active: boolean;
};

type Section = { id: number; name: string; capacity: number | null; room_number: string | null };
type SchoolClass = {
  id: number;
  name: string;
  academic_session_id: number;
  academic_session: { id: number; name: string };
  capacity: number | null;
  room_number: string | null;
  sections: Section[];
  subjects: Pick<Subject, "id" | "name" | "code" | "type">[];
};
type Subject = {
  id: number;
  name: string;
  code: string | null;
  type: "compulsory" | "optional" | "elective";
  academic_session_id: number;
  academic_session: { id: number; name: string };
  classes: Pick<SchoolClass, "id" | "name">[];
};

const initialSession = { name: "", starts_on: "", ends_on: "", is_active: true };
const initialClass = { academic_session_id: "", name: "", capacity: "", room_number: "" };
const initialSubject = { academic_session_id: "", class_ids: [] as string[], name: "", code: "", type: "compulsory" as Subject["type"] };

export default function AcademicPage() {
  const { user } = useSession();
  const [sessions, setSessions] = useState<AcademicSession[]>([]);
  const [classes, setClasses] = useState<SchoolClass[]>([]);
  const [subjects, setSubjects] = useState<Subject[]>([]);
  const [sessionForm, setSessionForm] = useState(initialSession);
  const [classForm, setClassForm] = useState(initialClass);
  const [subjectForm, setSubjectForm] = useState(initialSubject);
  const [sectionName, setSectionName] = useState<Record<number, string>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const canViewSessions = hasPermission(user, "academic_sessions.view");
  const canViewClasses = hasPermission(user, "classes.view");
  const canViewSubjects = hasPermission(user, "subjects.view");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const [sessionResult, classResult, subjectResult] = await Promise.all([
        canViewSessions ? apiRequest<{ data: AcademicSession[] }>("/academic-sessions") : Promise.resolve({ data: [] }),
        canViewClasses ? apiRequest<{ data: SchoolClass[] }>("/classes") : Promise.resolve({ data: [] }),
        canViewSubjects ? apiRequest<{ data: Subject[] }>("/subjects") : Promise.resolve({ data: [] }),
      ]);
      setSessions(sessionResult.data);
      setClasses(classResult.data);
      setSubjects(subjectResult.data);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setLoading(false);
    }
  }, [canViewClasses, canViewSessions, canViewSubjects]);

  useEffect(() => { void Promise.resolve().then(load); }, [load]);

  async function mutate(action: () => Promise<unknown>, success: string): Promise<boolean> {
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await action();
      setNotice(success);
      await load();
      return true;
    } catch (reason) {
      setError(errorMessage(reason));
      return false;
    } finally {
      setSaving(false);
    }
  }

  async function createSession(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const saved = await mutate(
      () => apiRequest("/academic-sessions", { method: "POST", body: { ...sessionForm } }),
      "Academic session added.",
    );
    if (saved) setSessionForm(initialSession);
  }

  async function toggleActive(session: AcademicSession) {
    await mutate(
      () => apiRequest(`/academic-sessions/${session.id}`, {
        method: "PUT",
        body: {
          name: session.name,
          starts_on: session.starts_on.slice(0, 10),
          ends_on: session.ends_on.slice(0, 10),
          is_active: !session.is_active,
        },
      }),
      session.is_active ? "Academic session deactivated." : "Active session updated.",
    );
  }

  async function deleteSession(session: AcademicSession) {
    if (!window.confirm(`Delete "${session.name}"? This is available only when no classes depend on it.`)) return;
    await mutate(() => apiRequest(`/academic-sessions/${session.id}`, { method: "DELETE" }), "Academic session deleted.");
  }

  async function createClass(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const saved = await mutate(
      () => apiRequest("/classes", {
        method: "POST",
        body: {
          ...classForm,
          academic_session_id: Number(classForm.academic_session_id),
          capacity: classForm.capacity ? Number(classForm.capacity) : null,
        },
      }),
      "Class added.",
    );
    if (saved) setClassForm(initialClass);
  }

  async function addSection(event: FormEvent<HTMLFormElement>, schoolClass: SchoolClass) {
    event.preventDefault();
    const name = sectionName[schoolClass.id]?.trim();
    if (!name) return;
    const saved = await mutate(
      () => apiRequest(`/classes/${schoolClass.id}/sections`, { method: "POST", body: { name } }),
      `Section added to ${schoolClass.name}.`,
    );
    if (saved) setSectionName((current) => ({ ...current, [schoolClass.id]: "" }));
  }

  async function deleteSection(section: Section) {
    if (!window.confirm(`Delete section "${section.name}"?`)) return;
    await mutate(() => apiRequest(`/sections/${section.id}`, { method: "DELETE" }), "Section deleted.");
  }

  async function deleteClass(schoolClass: SchoolClass) {
    if (!window.confirm(`Delete "${schoolClass.name}" and its sections?`)) return;
    await mutate(() => apiRequest(`/classes/${schoolClass.id}`, { method: "DELETE" }), "Class deleted.");
  }

  async function createSubject(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const saved = await mutate(
      () => apiRequest("/subjects", {
        method: "POST",
        body: {
          ...subjectForm,
          academic_session_id: Number(subjectForm.academic_session_id),
          class_ids: subjectForm.class_ids.map(Number),
          code: subjectForm.code || null,
        },
      }),
      "Subject added.",
    );
    if (saved) setSubjectForm(initialSubject);
  }

  async function deleteSubject(subject: Subject) {
    if (!window.confirm(`Delete subject "${subject.name}"?`)) return;
    await mutate(() => apiRequest(`/subjects/${subject.id}`, { method: "DELETE" }), "Subject deleted.");
  }

  return (
    <div className="page-stack">
      <header className="page-title-row">
        <div><div className="eyebrow">SCHOOL SETUP / ACADEMICS</div><h1>Academic structure<span className="heading-period">.</span></h1><p>Set up the sessions, classes, sections and subjects that organize your school year.</p></div>
        <button className="button button-secondary" disabled={loading} onClick={() => void load()}>↻ Refresh</button>
      </header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      {loading ? <div className="surface-card empty-state">Loading academic records…</div> : (
        <div className="academic-grid">
          {canViewSessions && <section className="surface-card setup-card">
            <div className="card-heading"><div><div className="eyebrow">01 / SCHOOL YEAR</div><h2>Academic sessions</h2></div><span className="count-pill">{sessions.length}</span></div>
            {hasPermission(user, "academic_sessions.create") && <form className="compact-form" onSubmit={createSession}>
              <label className="field-label">Session name<input placeholder="2026–2027" value={sessionForm.name} onChange={(event) => setSessionForm({ ...sessionForm, name: event.target.value })} required /></label>
              <div className="form-two-col">
                <label className="field-label">Starts<input type="date" value={sessionForm.starts_on} onChange={(event) => setSessionForm({ ...sessionForm, starts_on: event.target.value })} required /></label>
                <label className="field-label">Ends<input type="date" value={sessionForm.ends_on} onChange={(event) => setSessionForm({ ...sessionForm, ends_on: event.target.value })} required /></label>
              </div>
              <label className="checkbox-line"><input type="checkbox" checked={sessionForm.is_active} onChange={(event) => setSessionForm({ ...sessionForm, is_active: event.target.checked })} /> Set as active session</label>
              <button className="button button-primary" disabled={saving} type="submit">Add session <span aria-hidden="true">+</span></button>
            </form>}
            <div className="record-list">
              {sessions.map((session) => <article className="record-row" key={session.id}>
                <div className="record-main"><strong>{session.name}</strong><small>{formatDate(session.starts_on)} — {formatDate(session.ends_on)}</small></div>
                {session.is_active ? <span className="active-badge">Active</span> : <button className="small-action" disabled={!hasPermission(user, "academic_sessions.edit") || saving} onClick={() => void toggleActive(session)}>Set active</button>}
                {hasPermission(user, "academic_sessions.delete") && <button className="delete-action" aria-label={`Delete ${session.name}`} disabled={saving} onClick={() => void deleteSession(session)}>×</button>}
              </article>)}
              {sessions.length === 0 && <p className="empty-copy">No academic sessions yet.</p>}
            </div>
          </section>}

          {canViewClasses && <section className="surface-card setup-card">
            <div className="card-heading"><div><div className="eyebrow">02 / CLASSROOMS</div><h2>Classes & sections</h2></div><span className="count-pill">{classes.length}</span></div>
            {hasPermission(user, "classes.create") && <form className="compact-form" onSubmit={createClass}>
              <label className="field-label">Academic session<select value={classForm.academic_session_id} onChange={(event) => setClassForm({ ...classForm, academic_session_id: event.target.value })} required>
                <option value="">Select a session</option>{sessions.map((session) => <option key={session.id} value={session.id}>{session.name}</option>)}
              </select></label>
              <div className="form-two-col">
                <label className="field-label">Class name<input placeholder="Class 1" value={classForm.name} onChange={(event) => setClassForm({ ...classForm, name: event.target.value })} required /></label>
                <label className="field-label">Capacity<input type="number" min="1" placeholder="Optional" value={classForm.capacity} onChange={(event) => setClassForm({ ...classForm, capacity: event.target.value })} /></label>
              </div>
              <label className="field-label">Room number<input placeholder="Optional" value={classForm.room_number} onChange={(event) => setClassForm({ ...classForm, room_number: event.target.value })} /></label>
              <button className="button button-primary" disabled={saving || sessions.length === 0} type="submit">Add class <span aria-hidden="true">+</span></button>
            </form>}
            <div className="record-list">
              {classes.map((schoolClass) => <article className="class-record" key={schoolClass.id}>
                <div className="class-record-heading">
                  <div className="record-main"><strong>{schoolClass.name}</strong><small>{schoolClass.academic_session.name}{schoolClass.room_number ? ` · Room ${schoolClass.room_number}` : ""}</small></div>
                  {hasPermission(user, "classes.delete") && <button className="delete-action" aria-label={`Delete ${schoolClass.name}`} disabled={saving} onClick={() => void deleteClass(schoolClass)}>×</button>}
                </div>
                <div className="section-pills">
                  {schoolClass.sections.map((section) => <span className="section-pill" key={section.id}>{section.name}{hasPermission(user, "classes.delete") && <button aria-label={`Delete section ${section.name}`} onClick={() => void deleteSection(section)}>×</button>}</span>)}
                  {schoolClass.sections.length === 0 && <span className="empty-copy">No sections assigned</span>}
                </div>
                {hasPermission(user, "classes.create") && <form className="inline-add" onSubmit={(event) => void addSection(event, schoolClass)}>
                  <input aria-label={`New section for ${schoolClass.name}`} placeholder="Section name" value={sectionName[schoolClass.id] ?? ""} onChange={(event) => setSectionName({ ...sectionName, [schoolClass.id]: event.target.value })} required />
                  <button className="small-action" disabled={saving} type="submit">Add section</button>
                </form>}
              </article>)}
              {classes.length === 0 && <p className="empty-copy">Add an academic session, then create a class.</p>}
            </div>
          </section>}

          {canViewSubjects && <section className="surface-card setup-card">
            <div className="card-heading"><div><div className="eyebrow">03 / CURRICULUM</div><h2>Subjects</h2></div><span className="count-pill">{subjects.length}</span></div>
            {hasPermission(user, "subjects.create") && <form className="compact-form" onSubmit={createSubject}>
              <label className="field-label">Academic session<select value={subjectForm.academic_session_id} onChange={(event) => setSubjectForm({ ...subjectForm, academic_session_id: event.target.value, class_ids: [] })} required>
                <option value="">Select a session</option>{sessions.map((session) => <option key={session.id} value={session.id}>{session.name}</option>)}
              </select></label>
              <fieldset className="field-label" disabled={!subjectForm.academic_session_id}>
                <legend>Classes</legend>
                {classes.filter((schoolClass) => String(schoolClass.academic_session_id) === subjectForm.academic_session_id).map((schoolClass) => (
                  <label className="checkbox-line" key={schoolClass.id}>
                    <input
                      type="checkbox"
                      checked={subjectForm.class_ids.includes(String(schoolClass.id))}
                      onChange={(event) => setSubjectForm({
                        ...subjectForm,
                        class_ids: event.target.checked
                          ? [...subjectForm.class_ids, String(schoolClass.id)]
                          : subjectForm.class_ids.filter((id) => id !== String(schoolClass.id)),
                      })}
                    />
                    {schoolClass.name}
                  </label>
                ))}
                {classes.filter((schoolClass) => String(schoolClass.academic_session_id) === subjectForm.academic_session_id).length === 0 && <small>Create a class for this session first.</small>}
              </fieldset>
              <div className="form-two-col">
                <label className="field-label">Subject name<input placeholder="Mathematics" value={subjectForm.name} onChange={(event) => setSubjectForm({ ...subjectForm, name: event.target.value })} required /></label>
                <label className="field-label">Code<input placeholder="Optional" value={subjectForm.code} onChange={(event) => setSubjectForm({ ...subjectForm, code: event.target.value })} /></label>
              </div>
              <label className="field-label">Subject type<select value={subjectForm.type} onChange={(event) => setSubjectForm({ ...subjectForm, type: event.target.value as Subject["type"] })}>
                <option value="compulsory">Compulsory</option><option value="optional">Optional</option><option value="elective">Elective</option>
              </select></label>
              <button className="button button-primary" disabled={saving || sessions.length === 0 || subjectForm.class_ids.length === 0} type="submit">Add subject <span aria-hidden="true">+</span></button>
            </form>}
            <div className="record-list">
              {subjects.map((subject) => <article className="record-row" key={subject.id}>
                <div className="record-main"><strong>{subject.name}</strong><small>{subject.academic_session.name}{subject.code ? ` · ${subject.code}` : ""} · {subject.type} · {subject.classes.map((schoolClass) => schoolClass.name).join(", ")}</small></div>
                {hasPermission(user, "subjects.delete") && <button className="delete-action" aria-label={`Delete ${subject.name}`} disabled={saving} onClick={() => void deleteSubject(subject)}>×</button>}
              </article>)}
              {subjects.length === 0 && <p className="empty-copy">No subjects have been added.</p>}
            </div>
          </section>}
          {!canViewSessions && !canViewClasses && !canViewSubjects && <div className="surface-card empty-state">Your account does not have permission to view academic setup.</div>}
        </div>
      )}
    </div>
  );
}
