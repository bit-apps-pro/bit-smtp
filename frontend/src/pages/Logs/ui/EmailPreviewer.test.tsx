import { render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import EmailPreviewer from './EmailPreviewer'

describe('EmailPreviewer dark-mode body color', () => {
  it('injects the light text color and a dark color-scheme when isDark is true', async () => {
    render(<EmailPreviewer html="<p>Hello</p>" isDark />)
    const iframe = screen.getByTitle('Email Preview') as HTMLIFrameElement

    await waitFor(() => expect(iframe.srcdoc).toContain('Hello'))

    expect(iframe.srcdoc).toContain('<meta name="color-scheme" content="dark">')
    expect(iframe.srcdoc).toContain('body { color: #f8fafc; }')
  })

  it('injects black text and a light color-scheme when isDark is false', async () => {
    render(<EmailPreviewer html="<p>Hello</p>" isDark={false} />)
    const iframe = screen.getByTitle('Email Preview') as HTMLIFrameElement

    await waitFor(() => expect(iframe.srcdoc).toContain('Hello'))

    expect(iframe.srcdoc).toContain('<meta name="color-scheme" content="light">')
    expect(iframe.srcdoc).toContain('body { color: #000000; }')
  })

  it('keeps the injected rule low-specificity so the email HTML can still set its own color', async () => {
    render(<EmailPreviewer html='<p style="color: red">Hello</p>' isDark />)
    const iframe = screen.getByTitle('Email Preview') as HTMLIFrameElement

    await waitFor(() => expect(iframe.srcdoc).toContain('Hello'))

    // the injected default targets only the bare `body` element — no `!important`,
    // no id/class selector — so it never outranks the email's own inline/CSS color.
    expect(iframe.srcdoc).not.toContain('!important')
    expect(iframe.srcdoc).toContain('style="color: red"')
  })
})
