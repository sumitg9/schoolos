import { NextResponse } from "next/server";

export async function GET(request: Request) {
  const hostname = new URL(request.url).hostname;
  const isLoopback = ["localhost", "127.0.0.1", "::1"].includes(hostname);

  if (process.env.NODE_ENV !== "development" || !isLoopback) {
    return new NextResponse(null, { status: 404, headers: { "Cache-Control": "no-store" } });
  }

  const email = process.env.DEV_LOGIN_EMAIL;
  const password = process.env.DEV_LOGIN_PASSWORD;

  return NextResponse.json(
    {
      ...(email && password ? { email, password } : {}),
      demoPassword: "DemoSchool2026!",
      demoAccounts: [
        { label: "Parent", email: "parent.demo@example.test" },
        { label: "Student", email: "student.demo@example.test" },
        { label: "Teacher", email: "teacher.demo@example.test" },
        { label: "Non-teaching staff", email: "staff.demo@example.test" },
      ],
    },
    { headers: { "Cache-Control": "no-store, private" } },
  );
}
