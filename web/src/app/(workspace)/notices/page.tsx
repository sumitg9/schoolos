"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { formatDateTime } from "@/lib/date";
import { hasPermission } from "@/lib/types";
import { useSession } from "@/components/workspace-shell";

type Notice = {
  id: number; title: string; body: string; audience: "all" | "staff" | "students" | "parents";
  status: "draft" | "published"; publish_at: string | null; expires_at: string | null;
  creator: { name: string } | null;
};
const audienceLabel = { all: "Everyone", staff: "Staff", students: "Students", parents: "Parents / guardians" };

export default function NoticesPage() {
  const { user } = useSession();
  const [notices, setNotices] = useState<Notice[]>([]);
  const [form, setForm] = useState({ title: "", body: "", audience: "all" as Notice["audience"], publish_at: "", expires_at: "" });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const canCreate = hasPermission(user, "notices.create");
  const canPublish = hasPermission(user, "notices.publish");
  const canDelete = hasPermission(user, "notices.delete");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const result = await apiRequest<{ data: { data: Notice[] } }>("/notices?per_page=100");
      setNotices(result.data.data);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void Promise.resolve().then(load); }, [load]);

  async function createNotice(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setNotice("");
    try {
      await apiRequest("/notices", {
        method: "POST",
        body: {
          ...form,
          publish_at: form.publish_at || null,
          expires_at: form.expires_at || null,
        },
      });
      setForm({ title: "", body: "", audience: "all", publish_at: "", expires_at: "" });
      setNotice("Announcement created.");
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  async function publish(item: Notice) {
    try {
      await apiRequest(`/notices/${item.id}/publish`, { method: "POST" });
      setNotice(`"${item.title}" is published.`);
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }

  async function remove(item: Notice) {
    if (!window.confirm(`Delete "${item.title}"?`)) return;
    try {
      await apiRequest(`/notices/${item.id}`, { method: "DELETE" });
      setNotice("Announcement deleted.");
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    }
  }

  return (
    <div className="page-stack">
      <header className="page-title-row">
        <div><div className="eyebrow">SCHOOL COMMUNICATIONS</div><h1>Notices & announcements<span className="heading-period">.</span></h1><p>Share school updates with staff, students, parents or the whole community.</p></div>
        <button className="button button-secondary" disabled={loading} onClick={() => void load()}>↻ Refresh</button>
      </header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      {canCreate && <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">NEW ANNOUNCEMENT</div><h2>Compose notice</h2></div></div>
        <form className="compact-form" onSubmit={createNotice}>
          <label className="field-label">Title<input value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} maxLength={180} required /></label>
          <label className="field-label">Message<textarea rows={5} value={form.body} onChange={(event) => setForm({ ...form, body: event.target.value })} maxLength={20000} required /></label>
          <div className="form-two-col">
            <label className="field-label">Audience<select value={form.audience} onChange={(event) => setForm({ ...form, audience: event.target.value as Notice["audience"] })}>{Object.entries(audienceLabel).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
            <label className="field-label">Publish at (optional)<input type="datetime-local" value={form.publish_at} onChange={(event) => setForm({ ...form, publish_at: event.target.value })} /></label>
            <label className="field-label">Expires at (optional)<input type="datetime-local" min={form.publish_at || undefined} value={form.expires_at} onChange={(event) => setForm({ ...form, expires_at: event.target.value })} /></label>
          </div>
          <p className="field-help">Staff-created notices remain drafts until approved by an administrator. Administrators publish notices on creation.</p>
          <button className="button button-primary" disabled={saving}>{saving ? "Saving…" : "Create announcement"}</button>
        </form>
      </section>}
      <section className="surface-card setup-card">
        <div className="card-heading"><div><div className="eyebrow">BULLETIN BOARD</div><h2>Announcements</h2></div><span className="count-pill">{notices.length}</span></div>
        {loading ? <div className="empty-state">Loading notices…</div> : notices.length === 0 ? <div className="empty-state">No announcements are available.</div> : <div className="notice-list">
          {notices.map((item) => <article className="notice-item" key={item.id}>
            <div className="notice-item-heading"><div><h3>{item.title}</h3><span>{audienceLabel[item.audience]} · {item.status}{item.creator ? ` · ${item.creator.name}` : ""}</span></div><div className="button-row">{canPublish && item.status === "draft" && <button className="button button-secondary" onClick={() => void publish(item)}>Publish</button>}{canDelete && <button className="button button-secondary" onClick={() => void remove(item)}>Delete</button>}</div></div>
            <p>{item.body}</p>
            {(item.publish_at || item.expires_at) && <small>{item.publish_at ? `Starts ${formatDateTime(item.publish_at)}` : ""}{item.publish_at && item.expires_at ? " · " : ""}{item.expires_at ? `Expires ${formatDateTime(item.expires_at)}` : ""}</small>}
          </article>)}
        </div>}
      </section>
    </div>
  );
}
