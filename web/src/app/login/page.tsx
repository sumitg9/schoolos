"use client";

import { useEffect, useState, type FormEvent } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { apiRequest, errorMessage } from "@/lib/api";

type DemoAccount = { label: string; email: string };

export default function LoginPage() {
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [demoAccounts, setDemoAccounts] = useState<DemoAccount[]>([]);
  const [demoPassword, setDemoPassword] = useState("");

  useEffect(() => {
    if (process.env.NODE_ENV !== "development") return;
    let active = true;

    fetch("/api/dev-login-credentials", { cache: "no-store" })
      .then(async (response) => {
        if (!response.ok) throw new Error("Local login autofill is not available.");
        const credentials: unknown = await response.json();
        if (typeof credentials !== "object" || credentials === null) {
          throw new Error("Local development login data is invalid.");
        }
        if (active) {
          if ("email" in credentials && "password" in credentials
            && typeof credentials.email === "string" && typeof credentials.password === "string") {
            setEmail(credentials.email);
            setPassword(credentials.password);
          }
          if ("demoPassword" in credentials && typeof credentials.demoPassword === "string"
            && "demoAccounts" in credentials && Array.isArray(credentials.demoAccounts)) {
            const accounts = credentials.demoAccounts.filter((account): account is DemoAccount =>
              typeof account === "object" && account !== null
              && "label" in account && typeof account.label === "string"
              && "email" in account && typeof account.email === "string");
            setDemoAccounts(accounts);
            setDemoPassword(credentials.demoPassword);
          }
        }
      })
      .catch(() => {
        if (active) setError("Local login autofill is unavailable. Enter your credentials manually.");
      });

    return () => { active = false; };
  }, []);

  async function signIn(loginEmail: string, loginPassword: string) {
    setError("");
    setSubmitting(true);

    try {
      const { user } = await apiRequest<{ user: { roles: { name: string }[] } }>("/login", { method: "POST", body: { email: loginEmail, password: loginPassword } });
      const isPortalUser = user.roles.some((role) => role.name === "parent" || role.name === "student");
      router.replace(isPortalUser ? "/portal" : "/");
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setSubmitting(false);
    }
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await signIn(email, password);
  }

  return (
    <main className="login-page">
      <section className="login-brand-panel">
        <div className="login-brand">
          <span className="brand-mark">S</span>
          <span><strong>schoolOS</strong><small>ADMIN CONSOLE</small></span>
        </div>
        <div className="login-brand-message">
          <div className="eyebrow"><span className="eyebrow-line" /> SCHOOL MANAGEMENT, SIMPLIFIED</div>
          <h1>A better day<br />at <em>school.</em></h1>
          <p>One thoughtful workspace for the people who make learning happen.</p>
        </div>
        <div className="login-illustration" aria-hidden="true">
          <div className="login-sun" />
          <div className="login-hill hill-back" /><div className="login-hill hill-front" />
          <div className="login-school"><div className="login-roof" /><div className="login-school-body"><span /><span /><span /><b /></div></div>
          <div className="login-tree tree-left" /><div className="login-tree tree-right" />
        </div>
        <div className="login-footer">A calm, connected school day starts here.</div>
      </section>
      <section className="login-form-panel">
        <div className="login-form-wrap">
          <div className="eyebrow">WELCOME BACK</div>
          <h2>Sign in to your school</h2>
          <p className="muted-copy">{process.env.NODE_ENV === "development" ? "Sign in with your account or use a local demo account for testing." : "Use the account configured for this school."}</p>
          <form onSubmit={submit} className="form-stack">
            <label className="field-label" htmlFor="email">Email address
              <input autoComplete="username" id="email" name="email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} required />
            </label>
            <label className="field-label" htmlFor="password">Password
              <input autoComplete="current-password" id="password" name="password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} required />
            </label>
            {error && <div className="notice notice-error" role="alert">{error}</div>}
            <button className="button button-primary button-full" disabled={submitting} type="submit">
              {submitting ? "Signing in…" : "Sign in"} <span aria-hidden="true">→</span>
            </button>
          </form>
          {demoAccounts.length > 0 && <div className="form-stack" aria-label="Demo account sign-in">
            {demoAccounts.map((account) => <button
              className="button button-secondary button-full"
              disabled={submitting}
              key={account.email}
              onClick={() => void signIn(account.email, demoPassword)}
              type="button"
            >
              {submitting ? "Signing in…" : `Continue as ${account.label}`}
            </button>)}
          </div>}
          <div className="login-security"><span className="lock-symbol">▣</span> Protected with secure session authentication</div>
          <p className="muted-copy">Parent or student? <Link href="/register">Create a portal account</Link></p>
        </div>
      </section>
    </main>
  );
}
