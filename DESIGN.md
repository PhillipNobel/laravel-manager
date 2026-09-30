---
name: Laravel Manager
description: A restrained, single-server Laravel control room.
colors:
  slate-ink: "#495464"
  paper-canvas: "#F4F4F2"
  soft-gray: "#E8E8E8"
  blue-gray: "#BBBFCA"
  control-outline: "hsl(224 12.4% 54%)"
  error-red: "hsl(0 72.22% 50.59%)"
  error-foreground: "hsl(0 0% 98%)"
typography:
  title:
    fontFamily: "ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 600
    lineHeight: 1.333
    letterSpacing: "-0.025em"
  headline:
    fontFamily: "ui-sans-serif, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 600
    lineHeight: 1.5
  body:
    fontFamily: "ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 400
    lineHeight: 1.429
  label:
    fontFamily: "ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.875rem"
    fontWeight: 500
    lineHeight: 1.429
  technical:
    fontFamily: "ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace"
    fontSize: "0.75rem"
    fontWeight: 400
    lineHeight: 1.333
  metadata:
    fontFamily: "ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 600
    lineHeight: 1.333
rounded:
  sm: "4px"
  md: "6px"
  lg: "8px"
  full: "9999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "16px"
  lg: "24px"
  xl: "32px"
components:
  button-primary:
    backgroundColor: "{colors.slate-ink}"
    textColor: "{colors.paper-canvas}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "40px"
  button-outline:
    backgroundColor: "{colors.paper-canvas}"
    textColor: "{colors.slate-ink}"
    typography: "{typography.label}"
    rounded: "{rounded.md}"
    padding: "8px 16px"
    height: "40px"
  text-field:
    backgroundColor: "{colors.paper-canvas}"
    textColor: "{colors.slate-ink}"
    typography: "{typography.body}"
    rounded: "{rounded.md}"
    padding: "8px 12px"
    height: "40px"
  status-outline:
    backgroundColor: "{colors.soft-gray}"
    textColor: "{colors.slate-ink}"
    typography: "{typography.metadata}"
    rounded: "{rounded.full}"
    padding: "2px 10px"
  sidebar-link-active:
    backgroundColor: "{colors.slate-ink}"
    textColor: "{colors.paper-canvas}"
    rounded: "{rounded.md}"
    padding: "4px 8px"
  app-card:
    backgroundColor: "{colors.paper-canvas}"
    textColor: "{colors.slate-ink}"
    rounded: "{rounded.lg}"
    padding: "16px"
  domain-preview:
    backgroundColor: "{colors.soft-gray}"
    textColor: "{colors.slate-ink}"
    typography: "{typography.technical}"
    rounded: "{rounded.md}"
    padding: "8px 12px"
---

# Design System: Laravel Manager

## Overview

**Creative North Star: "The Single-Server Control Room"**

Laravel Manager adapts April UI v1.3 into a restrained operating surface for managing Laravel applications on one server. Paper and cool-gray surfaces, blue-gray separators, and slate actions keep attention on app identity, status, domains, branches, PHP versions, and server settings. System sans carries interface text; monospace marks technical values an operator checks or copies.

At desktop sizes, the April sidebar anchors Apps, Server, and Settings navigation while each page uses a focused heading and action area above a compact list or form. At narrow widths, navigation opens as a slide-in panel, app rows become cards, environment details stack into one column, and form actions stack. Surface tints and borders provide depth without prominent shadows.

**Key Characteristics:**
- Paper and cool gray establish the canvas, surfaces, and sidebar.
- Slate carries primary actions, selected navigation, focus, and the compact product mark.
- Blue gray defines secondary borders and dividers.
- Compact system sans typography carries labels and operational details.
- Technical values use monospace; surfaces stay flat and border-led.

## Colors

The palette pairs warm off-white paper with cool gray surfaces, blue-gray lines, and dark slate; red remains reserved for destructive and error states.

### Primary
- **Slate Ink** (#495464): Primary interface text, actions, selected navigation, keyboard focus, and the compact Laravel Manager mark; paper-colored text maintains readable contrast on slate fills.

### Neutral
- **Paper Canvas** (#F4F4F2): The main page, form, card, and popover surface.
- **Soft Gray** (#E8E8E8): The sidebar, table headings, and quiet informational surfaces.
- **Blue Gray** (#BBBFCA): Borders and dividers that organize rows and containers.
- **Control Outline** (hsl(224 12.4% 54%)): A darker blue-gray derived from the palette for form-field edges that need clear visual contrast.

### Named Rules
**The Slate Action Rule.** Use slate for primary actions, focus, and selected navigation; use paper and gray surfaces to carry the rest of the screen.

## Typography

**Display Font:** System sans stack; no separate display face.
**Body Font:** System sans stack.
**Label/Mono Font:** System sans for labels; system monospace for technical values.

**Character:** The system font stack feels familiar and direct, with weight and spacing doing the hierarchy work. Monospace is a quiet cue for domains, paths, and similar technical values.

### Hierarchy
- **Title** (600, 24px, 32px line-height, tight tracking): Page headings such as Apps, Create App, and Settings.
- **Headline** (600, 16px, 24px line-height): Section headings and key list labels.
- **Body** (400, 14px, 20px line-height): Explanations, helper text, and common interface copy; 16px body text appears where additional reading comfort is useful.
- **Label** (500, 14px, 20px): Field labels and buttons.
- **Technical** (400, 12px or 14px): Domains, paths, and commit values use the system monospace stack at the surrounding text size.

### Named Rules
**The Technical Value Rule.** Use monospace for values that behave like technical identifiers; keep ordinary labels and prose in the system sans stack.

## Layout

Use a persistent 16rem sidebar from the medium breakpoint upward and a 56px top bar. On mobile, navigation becomes an 18rem slide-in panel over a dark scrim. Main content uses a responsive gutter (16px on narrow screens, 24px from small screens, and 32px on large screens) inside an 80rem maximum width. Focused forms narrow to 42rem for app creation and 48rem for settings.

A 4px spacing unit creates the rhythm: labels sit close to controls, related fields group in 8–20px steps, and page sections separate by 24–32px. Paired form fields become two columns above 640px; app lists switch from cards to a table at 1024px. Keep narrow-screen actions stacked and easy to tap.

## Elevation & Depth

The interface is flat at rest. Thin borders separate rows and frame controls; paper, cool gray, and blue-gray distinguish the sidebar, page, and grouped content. The mobile navigation scrim darkens the page while the navigation panel remains a solid surface. April's opt-in `.dark` class reverses slate and paper roles using the same palette. There is no content-card shadow vocabulary.

### Named Rules
**The Border-Led Depth Rule.** Show grouping with a tint or border before adding elevation.

## Shapes

The shape language is gently rounded and consistent: common inputs and buttons use a 6px radius, list containers use 8px, and status badges and avatars are fully rounded. Thin borders define edges. Icons are crisp 16px line drawings that inherit the surrounding text color.

## Components

### Buttons
Buttons are compact and clear, with a 40px control height.
- **Shape:** 6px corners.
- **Primary:** Slate fill with paper text; 8px vertical and 16px horizontal padding.
- **Outline:** Paper fill, blue-gray border, and slate text.
- **Hover / Focus:** Secondary controls take a soft-gray tint. Keyboard focus uses a 2px slate ring with an offset.
- **Disabled:** The control dims and stops accepting pointer input.

### Chips / Status Badges
Status is a small, pill-shaped label rather than a large colored panel. Neutral states use an outline; active, provisioning, and failed states use the semantic secondary, primary, and destructive treatments.

### Cards / Containers
App cards and the desktop table use a paper fill, a thin blue-gray border, and 8px corners. Mobile cards keep 16px internal padding. Detail and settings sections rely on horizontal rules rather than nested decorative cards.

### Inputs / Fields
Fields use the paper background, a 1px control-outline border, 6px corners, and a 40px height. Focus uses the slate ring. Validation messages use the destructive color and remain adjacent to the affected field.

### Navigation
The desktop sidebar uses a soft-gray surface, compact group labels, and 16px line icons. The active item uses a slate fill with paper text. On mobile, the same menu slides in over a scrim; a compact top bar keeps the navigation trigger available.

### Server Page

The Server page uses a compact, border-led definition list for operating system, PHP runtime, default app PHP version, and configured server values. Domains, paths, URLs, and IP addresses use monospace. Fixed local software checks appear as divided rows with a short version string and a small status badge. Keep the page read-only and make its Settings link an outline action.

### Domain Preview
A live domain preview sits in a soft-gray, blue-gray-bordered field with monospace text, distinguishing a generated technical value from editable inputs.

## Do's and Don'ts

### Do:
- **Do** use the supplied paper, gray, blue-gray, and slate palette for surface, divider, text, and action roles.
- **Do** use monospace for domains, paths, and other technical values.
- **Do** separate app data and settings with compact spacing, readable labels, and thin borders.
- **Do** carry the sidebar menu into a slide-in mobile panel and present mobile app rows as cards.

### Don't:
- **Don't** turn the control room into a decorative marketing surface.
- **Don't** add prominent shadows to ordinary cards or detail sections.
- **Don't** use monospace for ordinary interface prose or labels.
