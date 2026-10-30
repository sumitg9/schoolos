"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { createContext, useContext, useEffect, useMemo, useState } from "react";
import { ApiError, apiRequest, errorMessage } from "@/lib/api";
import { hasPermission, type User } from "@/lib/types";

type SessionContextValue = { user: User };
type SchoolOption = { id: number; name: string; slug: string };
const SessionContext = createContext<SessionContextValue | null>(null);

export function useSession(): SessionContextValue {
  const value = useContext(SessionContext);
  if (!value) {
    throw new Error("useSession must be used inside the workspace.");
  }
  return value;
}

const navigation: { href: string; label: string; permissions: string[]; icon: string; portalRoles?: string[] }[] = [
  { href: "/", label: "Overview", permissions: ["dashboard.view"], icon: "OV" },
  { href: "/portal", label: "My portal", permissions: [], icon: "PO", portalRoles: ["parent", "student"] },
  { href: "/academic", label: "Academic setup", permissions: ["academic_sessions.view"], icon: "AC" },
  { href: "/assessments", label: "Exams & report cards", permissions: ["exams.view", "marks.view"], icon: "EX" },
  { href: "/homework", label: "Homework", permissions: ["homework.view"], icon: "HW" },
  { href: "/timetable", label: "Timetable", permissions: ["timetable.view"], icon: "TT" },
  { href: "/notices", label: "Notices", permissions: ["notices.view"], icon: "NO" },
  { href: "/attendance", label: "Attendance", permissions: ["attendance.view"], icon: "AT" },
  { href: "/fees", label: "Fees & payments", permissions: ["fees.view"], icon: "FE" },
  { href: "/notifications", label: "Notifications", permissions: ["notifications.view"], icon: "NT" },
  { href: "/library", label: "Library", permissions: ["library.view"], icon: "LB" },
  { href: "/transport", label: "Transport", permissions: ["transport.view"], icon: "TR" },
  { href: "/expenses", label: "Expenses", permissions: ["expenses.view"], icon: "XP" },
  { href: "/assets", label: "Assets", permissions: ["assets.view"], icon: "AS" },
  { href: "/events", label: "Events", permissions: ["events.view"], icon: "EV" },
  { href: "/payroll", label: "Payroll", permissions: ["payroll.view"], icon: "PY" },
  { href: "/leave", label: "Leave management", permissions: ["leave.view"], icon: "LV" },
  { href: "/inventory", label: "Inventory", permissions: ["inventory.view"], icon: "IN" },
  { href: "/portal-accounts", label: "Portal activation", permissions: ["portal_accounts.create"], icon: "PA" },
  { href: "/schools", label: "Schools", permissions: ["schools.view"], icon: "SC" },
  { href: "/students", label: "Students", permissions: ["students.view"], icon: "ST" },
  { href: "/employees", label: "Employees", permissions: ["employees.view"], icon: "EM" },
  { href: "/teacher-assignments", label: "Teacher assignments", permissions: ["teacher_assignments.view"], icon: "TA" },
  { href: "/settings", label: "School profile", permissions: ["school_settings.view"], icon: "SP" },
  { href: "/access", label: "Access control", permissions: ["roles.view", "users.view"], icon: "RB" },
  { href: "/audit", label: "Audit trail", permissions: ["audit_logs.view"], icon: "AT" },
];

export function WorkspaceShell({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [user, setUser] = useState<User | null>(null);
  const [loadError, setLoadError] = useState("");
  const [actionError, setActionError] = useState("");
  const [schools, setSchools] = useState<SchoolOption[]>([]);
  const [activeSchoolId, setActiveSchoolId] = useState("");

  useEffect(() => {
    apiRequest<{ user: User }>("/me")
      .then(({ user: currentUser }) => setUser(currentUser))
      .catch((error: unknown) => {
        if (error instanceof ApiError && error.status === 401) {
          router.replace("/login");
          return;
        }
        setLoadError(errorMessage(error));
      });
  }, [router]);

  const links = useMemo(
    () => user ? navigation.filter((item) => item.portalRoles
      ? item.portalRoles.some((role) => user.roles.some((userRole) => userRole.name === role))
      : item.permissions.some((permission) => hasPermission(user, permission))) : [],
    [user],
  );
  const activeSchoolName = schools.find((school) => String(school.id) === activeSchoolId)?.name
    ?? user?.school?.name
    ?? "School workspace";

  useEffect(() => {
    if (user && pathname === "/" && user.roles.some((role) => role.name === "parent" || role.name === "student")) {
      router.replace("/portal");
    }
  }, [pathname, router, user]);

  useEffect(() => {
    if (!user?.roles.some((role) => role.name === "super-admin")) return;
    void apiRequest<{ data: SchoolOption[]; active_school_id: number | null }>("/schools")
      .then(({ data, active_school_id }) => {
        setSchools(data);
        setActiveSchoolId(String(active_school_id ?? ""));
      })
      .catch((error: unknown) => setActionError(errorMessage(error)));
  }, [user]);

  async function switchSchool(schoolId: string) {
    if (!schoolId) return;
    setActionError("");
    try {
      await apiRequest(`/schools/${schoolId}/switch`, { method: "POST" });
      setActiveSchoolId(schoolId);
      router.refresh();
      window.location.reload();
    } catch (error) {
      setActionError(errorMessage(error));
    }
  }

  async function signOut() {
    setActionError("");
    try {
      await apiRequest("/logout", { method: "POST" });
      router.replace("/login");
    } catch (error) {
      setActionError(errorMessage(error));
    }
  }

  if (!user) {
    return (
      <main className="center-screen">
        {loadError ? (
          <div className="notice notice-error" role="alert">
            <strong>Could not load your workspace.</strong>
            <span>{loadError}</span>
            <button className="button button-secondary" onClick={() => window.location.reload()}>Retry</button>
          </div>
        ) : <div className="loading-indicator">Loading your workspace…</div>}
      </main>
    );
  }

  return (
    <SessionContext.Provider value={{ user }}>
      <div className="workspace">
        <aside className="sidebar">
          <Link className="brand" href="/">
            <span className="brand-mark">S</span>
            <span><strong>schoolOS</strong><small>{user.roles.some((role) => role.name === "parent" || role.name === "student") ? "FAMILY PORTAL" : "SCHOOL WORKSPACE"}</small></span>
          </Link>
          <div className="school-chip">
            <span className="school-avatar">S</span>
            <span><strong>{activeSchoolName}</strong><small>{user.roles.some((role) => role.name === "super-admin") ? "Multi-school administration" : "School workspace"}</small></span>
          </div>
          <div className="nav-caption">WORKSPACE</div>
          <nav className="nav-list" aria-label="Main navigation">
            {links.map((item) => (
              <Link
                aria-current={pathname === item.href ? "page" : undefined}
                className={`nav-link${pathname === item.href ? " nav-link-active" : ""}`}
                href={item.href}
                key={item.href}
              >
                <span className="nav-icon">{item.icon}</span>
                <span>{item.label}</span>
              </Link>
            ))}
          </nav>
          <div className="sidebar-bottom">
            <div className="phase-note"><span className="status-dot" /> {user.roles.some((role) => role.name === "parent" || role.name === "student") ? "Family portal" : "School workspace"}</div>
            <div className="account-row">
              <div className="user-avatar">{user.name.slice(0, 1).toUpperCase()}</div>
              <div className="account-info"><strong>{user.name}</strong><small>{user.roles[0]?.label ?? "User"}</small></div>
              <button className="icon-button" aria-label="Sign out" title="Sign out" onClick={signOut}>↗</button>
            </div>
          </div>
        </aside>
        <main className="main-panel">
          <header className="topbar">
            <div className="breadcrumb"><span>School</span><span className="breadcrumb-separator">/</span><strong>{links.find((item) => item.href === pathname)?.label ?? "Workspace"}</strong></div>
            <div className="topbar-right">{schools.length > 0 && <label className="field-label school-switcher">School<select aria-label="Active school" value={activeSchoolId} onChange={(event) => void switchSchool(event.target.value)}>{schools.map((school) => <option key={school.id} value={school.id}>{school.name}</option>)}</select></label>}<span className="connection-indicator" /><span>Connected</span><div className="topbar-avatar">{user.name.slice(0, 1).toUpperCase()}</div></div>
          </header>
          <div className="page-content">
            {actionError && <div className="notice notice-error" role="alert">{actionError}</div>}
            {children}
          </div>
        </main>
      </div>
    </SessionContext.Provider>
  );
}
