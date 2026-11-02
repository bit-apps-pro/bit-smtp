import { render, screen, waitFor } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import EmailPreviewer from './EmailPreviewer'

/** Renders the previewer and waits until its srcdoc holds the email HTML. */
async function renderPreview(html: string): Promise<HTMLIFrameElement> {
  render(<EmailPreviewer html={html} />)
  const iframe = screen.getByTitle('Email Preview') as HTMLIFrameElement
  await waitFor(() => expect(iframe.srcdoc).toContain('Hello'))
  return iframe
}

describe('EmailPreviewer', () => {
  it('renders the email on white paper with dark text and a light color-scheme', async () => {
    const iframe = await renderPreview('<p>Hello</p>')

    expect(iframe.srcdoc).toContain('<meta name="color-scheme" content="light">')
    expect(iframe.srcdoc).toContain('body { background: #ffffff; color: #000000; }')
  })

  it('keeps the defaults low-specificity so the email HTML can still set its own colors', async () => {
    const iframe = await renderPreview('<p style="color: red">Hello</p>')

    expect(iframe.srcdoc).not.toContain('!important')
    expect(iframe.srcdoc).toContain('style="color: red"')
  })

  it('grants the sandbox no permissions so email HTML cannot run scripts or reach the admin page', async () => {
    const iframe = await renderPreview('<p>Hello</p>')

    expect(iframe.getAttribute('sandbox')).toBe('')
  })
})
