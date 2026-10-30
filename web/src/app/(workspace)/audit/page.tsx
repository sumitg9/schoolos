"use client";

import { useEffect, useState } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { formatDateTime } from "@/lib/date";

type AuditEntry = {
  id: number;
  action: string;
  subject_type: string | null;
  subject_id: number | null;
  ip_address: string | null;
  created_at: string;
  user: { id: number; name: string; email: string } | null;
};

export default function AuditPage() {
  const [entries, setEntries] = useState<AuditEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  useEffect(() => {
    apiRequest<{ data: AuditEntry[] }>("/audit-logs?per_page=50")
      .then(({ data }) => setEntries(data))
      .catch((reason: unknown) => setError(errorMessage(reason)))
      .finally(() => setLoading(false));
  }, []);

  return (
    <div className="page-stack">
      <header className="page-title-row">
        <div><div className="eyebrow">SCHOOL SETUP / ACCOUNTABILITY</div><h1>Audit trail<span className="heading-period">.</span></h1><p>A time-ordered record of important changes made in the system.</p></div>
      </header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      <section className="surface-card access-section">
        <div className="card-heading"><div><div className="eyebrow">RECENT ACTIVITY</div><h2>System events</h2></div><span className="count-pill">{entries.length}</span></div>
        {loading ? <div className="empty-state">Loading activity…</div> : <div className="table-scroll"><table className="data-table"><thead><tr><th>Action</th><th>Changed by</th><th>Record</th><th>Date & time</th></tr></thead><tbody>
          {entries.map((entry) => <tr key={entry.id}><td><span className="event-label">{entry.action.replaceAll("_", " ")}</span></td><td>{entry.user?.name ?? "System"}<small>{entry.user?.email ?? ""}</small></td><td>{entry.subject_type?.split("\\").pop() ?? "—"}{entry.subject_id ? ` #${entry.subject_id}` : ""}</td><td>{formatDateTime(entry.created_at)}</td></tr>)}
          {entries.length === 0 && <tr><td className="table-empty" colSpan={4}>No activity recorded yet.</td></tr>}
        </tbody></table></div>}
      </section>
    </div>
  );
}
