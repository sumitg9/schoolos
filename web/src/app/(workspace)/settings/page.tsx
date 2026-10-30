"use client";

import { useEffect, useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";
import { hasPermission } from "@/lib/types";
import { useSession } from "@/components/workspace-shell";

type Settings = {
  name: string;
  address: string | null;
  phone: string | null;
  email: string | null;
  currency: string;
  timezone: string;
  date_format: string;
};

const blankSettings: Settings = {
  name: "",
  address: "",
  phone: "",
  email: "",
  currency: "INR",
  timezone: "Asia/Kolkata",
  date_format: "d-m-Y",
};

export default function SchoolSettingsPage() {
  const { user } = useSession();
  const [settings, setSettings] = useState(blankSettings);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const canEdit = hasPermission(user, "school_settings.edit");

  useEffect(() => {
    apiRequest<{ data: Settings }>("/settings/school")
      .then(({ data }) => setSettings(data))
      .catch((reason: unknown) => setError(errorMessage(reason)))
      .finally(() => setLoading(false));
  }, []);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSaving(true);
    setError("");
    setNotice("");
    try {
      const result = await apiRequest<{ data: Settings }>("/settings/school", {
        method: "PUT",
        body: { ...settings, date_format: "d-m-Y" },
      });
      setSettings(result.data);
      setNotice("School profile saved.");
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  function update<K extends keyof Settings>(key: K, value: Settings[K]) {
    setSettings((current) => ({ ...current, [key]: value }));
  }

  return (
    <div className="page-stack">
      <header className="page-title-row">
        <div><div className="eyebrow">SCHOOL SETUP / PREFERENCES</div><h1>School profile<span className="heading-period">.</span></h1><p>Keep the name, contact details and regional preferences used across the system.</p></div>
      </header>
      {error && <div className="notice notice-error" role="alert">{error}</div>}
      {notice && <div className="notice notice-success" role="status">{notice}</div>}
      <form className="surface-card settings-form" onSubmit={save}>
        <div className="card-heading"><div><div className="eyebrow">GENERAL INFORMATION</div><h2>School details</h2></div><div className="settings-symbol">S</div></div>
        {loading ? <div className="empty-state">Loading school profile…</div> : <>
          <div className="settings-grid">
            <label className="field-label field-span-two">School name<input value={settings.name} onChange={(event) => update("name", event.target.value)} required disabled={!canEdit} /></label>
            <label className="field-label field-span-two">Address<textarea rows={3} value={settings.address ?? ""} onChange={(event) => update("address", event.target.value)} disabled={!canEdit} /></label>
            <label className="field-label">Contact email<input type="email" value={settings.email ?? ""} onChange={(event) => update("email", event.target.value)} disabled={!canEdit} /></label>
            <label className="field-label">Phone number<input type="tel" value={settings.phone ?? ""} onChange={(event) => update("phone", event.target.value)} disabled={!canEdit} /></label>
          </div>
          <div className="settings-divider" />
          <div className="card-heading"><div><div className="eyebrow">REGIONAL PREFERENCES</div><h2>Time, date & currency</h2></div></div>
          <div className="settings-grid">
            <label className="field-label">Time zone<input value={settings.timezone} onChange={(event) => update("timezone", event.target.value)} disabled={!canEdit} required /></label>
            <label className="field-label">Date & time format<input value="DD-MM-YYYY HH:mm" readOnly /></label>
            <label className="field-label">Currency code<input maxLength={3} pattern="[A-Z]{3}" value={settings.currency} onChange={(event) => update("currency", event.target.value.toUpperCase())} disabled={!canEdit} required /><small className="field-help">Use a three-letter ISO currency code, for example INR.</small></label>
          </div>
          {canEdit && <div className="form-actions"><span className="muted-copy">Changes are recorded in the audit trail.</span><button className="button button-primary" disabled={saving} type="submit">{saving ? "Saving…" : "Save profile"} <span aria-hidden="true">→</span></button></div>}
          {!canEdit && <p className="muted-copy">You have read-only access to school settings.</p>}
        </>}
      </form>
    </div>
  );
}
