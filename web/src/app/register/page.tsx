"use client";

import Link from "next/link";
import { useState, type FormEvent } from "react";
import { apiRequest, errorMessage } from "@/lib/api";

export default function RegisterPage() {
  const [code, setCode] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [error, setError] = useState("");
  const [success, setSuccess] = useState(false);
  const [saving, setSaving] = useState(false);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setSaving(true);
    try {
      await apiRequest("/portal/register", {
        method: "POST",
        body: { code: code.trim().toUpperCase(), email, password, password_confirmation: passwordConfirmation },
      });
      setSuccess(true);
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSaving(false);
    }
  }

  return (
    <main className="login-page">
      <section className="login-brand-panel">
        <div className="login-brand"><span className="brand-mark">S</span><span><strong>schoolOS</strong><small>FAMILY PORTAL</small></span></div>
        <div className="login-brand-message"><div className="eyebrow"><span className="eyebrow-line" /> A CONNECTED SCHOOL COMMUNITY</div><h1>Your school,<br />closer <em>together.</em></h1><p>Stay informed about student progress, attendance and school updates.</p></div>
        <div className="login-footer">Keep your school-issued activation code private.</div>
      </section>
      <section className="login-form-panel"><div className="login-form-wrap">
        <div className="eyebrow">PORTAL ACCESS</div>
        <h2>{success ? "Account created" : "Create your portal account"}</h2>
        {success ? <><div className="notice notice-success" role="status">Your portal account is ready. Sign in with your email and password.</div><Link className="button button-primary button-full" href="/login">Continue to sign in →</Link></> : <>
          <p className="muted-copy">Enter the one-time code provided by your school. If an email is already recorded for your school account, use that address.</p>
          <form onSubmit={submit} className="form-stack">
            <label className="field-label" htmlFor="activation-code">School activation code<input autoComplete="one-time-code" id="activation-code" value={code} onChange={(event) => setCode(event.target.value.toUpperCase())} minLength={20} maxLength={64} required /></label>
            <label className="field-label" htmlFor="portal-email">Email address<input autoComplete="email" id="portal-email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} required /></label>
            <label className="field-label" htmlFor="portal-password">Password<input autoComplete="new-password" id="portal-password" type="password" minLength={12} value={password} onChange={(event) => setPassword(event.target.value)} required /></label>
            <label className="field-label" htmlFor="portal-password-confirm">Confirm password<input autoComplete="new-password" id="portal-password-confirm" type="password" minLength={12} value={passwordConfirmation} onChange={(event) => setPasswordConfirmation(event.target.value)} required /></label>
            {error && <div className="notice notice-error" role="alert">{error}</div>}
            <button className="button button-primary button-full" disabled={saving}>{saving ? "Creating account…" : "Create account"} <span aria-hidden="true">→</span></button>
          </form>
          <p className="muted-copy">Already registered? <Link href="/login">Sign in</Link></p>
        </>}
      </div></section>
    </main>
  );
}
