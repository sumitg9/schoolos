import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: "School ERP",
  description: "School administration foundation",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en">
      <body>{children}</body>
    </html>
  );
}
