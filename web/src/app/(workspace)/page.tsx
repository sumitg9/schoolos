"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { apiRequest, errorMessage } from "@/lib/api";

type Summary = {
  school_name: string;
  active_session: string | null;
  academic_sessions: number;
  classes: number;
  subjects: number;
  active_students: number;
  active_employees: number;
  active_users: number;
};

export default function DashboardPage() {
  const [summary, setSummary] = useState<Summary | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    apiRequest<{ data: Summary }>("/dashboard/summary")
      .then(({ data }) => setSummary(data))
      .catch((reason: unknown) => setError(errorMessage(reason)));
  }, []);

  const metrics = [
    { label: "Academic sessions", value: summary?.academic_sessions, hint: "Configured in your school", icon: "01" },
    { label: "Classes", value: summary?.classes, hint: "Across all sessions", icon: "02" },
    { label: "Subjects", value: summary?.subjects, hint: "Available for setup", icon: "03" },
    { label: "Active students", value: summary?.active_students, hint: "Current student records", icon: "04" },
    { label: "Active employees", value: summary?.active_employees, hint: "Current staff records", icon: "05" },
    { label: "Active users", value: summary?.active_users, hint: "Accounts with system access", icon: "06" },
  ];

  return (
    <div className="page-stack">
      <section className="welcome-panel">
        <div className="welcome-copy">
          <div className="eyebrow"><span className="eyebrow-line" /> SCHOOL ADMINISTRATION</div>
          <h1>Good morning<span className="heading-period">.</span></h1>
          <p>Your school’s foundation is taking shape. Start with the academic structure and school profile.</p>
          <Link className="button button-light" href="/academic">Continue setup <span aria-hidden="true">→</span></Link>
        </div>
        <div className="welcome-art" aria-hidden="true">
          <div className="art-sun" />
          <div className="art-building"><div className="art-roof" /><div className="art-columns"><i /><i /><i /></div><div className="art-steps" /></div>
          <div className="art-cloud art-cloud-one" /><div className="art-cloud art-cloud-two" />
          <div className="art-ground" />
        </div>
      </section>

      <section className="section-block">
        <div className="section-heading">
          <div><div className="eyebrow">YOUR SCHOOL AT A GLANCE</div><h2>Foundation overview</h2></div>
          <span className="live-tag"><span className="status-dot" /> Live data</span>
        </div>
        {error && <div className="notice notice-error" role="alert">{error}</div>}
        <div className="metric-grid">
          {metrics.map((metric, index) => (
            <article className="metric-card" key={metric.label}>
              <div className={`metric-icon metric-icon-${index}`}>{metric.icon}</div>
              <div className="metric-label">{metric.label}</div>
              <div className="metric-value">{metric.value ?? "—"}</div>
              <div className="metric-hint">{metric.hint}</div>
            </article>
          ))}
        </div>
      </section>

      <section className="bottom-grid">
        <article className="surface-card session-card">
          <div className="card-heading"><div><div className="eyebrow">ACADEMIC CALENDAR</div><h2>Current session</h2></div><div className="card-symbol">↗</div></div>
          {summary?.active_session ? (
            <><div className="current-session">{summary.active_session}</div><div className="session-state"><span className="status-dot" /> Active academic session</div></>
          ) : (
            <div className="empty-inline"><span className="empty-symbol">◷</span><span>No active session yet. Create one to organize your classes and subjects.</span></div>
          )}
          <Link className="text-link" href="/academic">Manage academic structure <span aria-hidden="true">→</span></Link>
        </article>
        <article className="surface-card checklist-card">
          <div className="card-heading"><div><div className="eyebrow">GETTING STARTED</div><h2>Setup checklist</h2></div><span className="checklist-count">01 / 03</span></div>
          <Link className="checklist-item" href="/settings"><span className="checklist-number">01</span><span className="checklist-text"><strong>School profile</strong><small>Add your name and contact details</small></span><span className="check-arrow">→</span></Link>
          <Link className="checklist-item" href="/academic"><span className="checklist-number">02</span><span className="checklist-text"><strong>Academic structure</strong><small>Set sessions, classes and subjects</small></span><span className="check-arrow">→</span></Link>
          <Link className="checklist-item" href="/access"><span className="checklist-number">03</span><span className="checklist-text"><strong>Team access</strong><small>Invite staff and set permissions</small></span><span className="check-arrow">→</span></Link>
        </article>
      </section>
    </div>
  );
}
