"use client";

import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { useSession } from "@/components/workspace-shell";

type School = { id: number; name: string; slug: string; status: string };

export default function SchoolsPage() {
  const { user } = useSession();
  const [schools, setSchools] = useState<School[]>([]);
  const [name, setName] = useState("");
  const [slug, setSlug] = useState("");
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const canManage = user.roles.some((role) => role.name === "super-admin");

  const load = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const result = await apiRequest<{ data: School[] }>("/schools");
      setSchools(result.data);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { void Promise.resolve().then(load); }, [load]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setNotice("");
    try {
      const result = await apiRequest<{ data: School }>("/schools", { method: "POST", body: { name, slug: slug || undefined } });
      setNotice(`${result.data.name} was created. Switch to it using the school selector above.`);
      setName("");
      setSlug("");
      await load();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="page-stack">
      <header className="page-title-row"><div><div className="eyebrow">TENANT ADMINISTRATION</div><h1>Schools<span className="heading-period">.</span></h1><p>Manage separate school workspaces in the shared database.</p></div><button className="button button-secondary" disabled={loading} onClick={() => void load()}>↻ Refresh</button></header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      {canManage && <section className="surface-card setup-card"><div className="card-heading"><div><div className="eyebrow">NEW WORKSPACE</div><h2>Create a school</h2></div></div>
        <form className="compact-form" onSubmit={submit}>
          <div className="form-two-col"><label className="field-label">School name<input required maxLength={180} value={name} onChange={(event) => setName(event.target.value)} /></label><label className="field-label">URL slug (optional)<input maxLength={180} pattern="[A-Za-z0-9_-]+" value={slug} onChange={(event) => setSlug(event.target.value)} /></label></div>
          <button className="button button-primary" disabled={saving}>{saving ? "Creating…" : "Create school"}</button>
        </form>
      </section>}
      <section className="surface-card"><div className="card-heading"><div><div className="eyebrow">SCHOOL DIRECTORY</div><h2>Workspaces</h2></div></div>
        {loading ? <div className="loading-indicator">Loading schools…</div> : <div className="data-table-wrap"><table className="data-table"><thead><tr><th>School</th><th>Slug</th><th>Status</th></tr></thead><tbody>
          {schools.map((school) => <tr key={school.id}><td>{school.name}</td><td>{school.slug}</td><td>{school.status}</td></tr>)}
        </tbody></table></div>}
      </section>
    </div>
  );
}
