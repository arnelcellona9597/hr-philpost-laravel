// App shell behaviour: drawer, display preferences, tooltips, account menu, search shortcut, session expiry.
const shell = document.getElementById('shell');

if (shell) {
    const root = document.documentElement
    const sidebar = document.getElementById('sidebar')
    const backdrop = document.getElementById('sidebar-backdrop')
    const desktop = matchMedia('(min-width: 64rem)')
    const setCookie = (name, value) => (document.cookie = `${name}=${value}; path=/; max-age=31536000; samesite=lax`)

    // ---- Mobile drawer: Esc / backdrop / close button, focus kept inside while open ----
    const opener = document.querySelector('[data-sidebar-open]')
    const focusables = () => [...sidebar.querySelectorAll('a[href], button:not([disabled])')].filter((el) => el.offsetParent !== null)
    const setOpen = (open) => {
      sidebar.dataset.open = String(open)
      backdrop.dataset.open = String(open)
      opener?.setAttribute('aria-expanded', String(open))
      document.body.style.overflow = open ? 'hidden' : ''
      if (open) requestAnimationFrame(() => focusables()[0]?.focus())
      else if (sidebar.contains(document.activeElement)) opener?.focus()
    }
    opener?.addEventListener('click', () => setOpen(true))
    backdrop.addEventListener('click', () => setOpen(false))
    sidebar.querySelector('[data-sidebar-close]')?.addEventListener('click', () => setOpen(false))
    sidebar.addEventListener('keydown', (e) => {
      if (sidebar.dataset.open !== 'true') return
      if (e.key === 'Escape') setOpen(false)
      if (e.key !== 'Tab') return
      const items = focusables()
      const first = items[0]
      const last = items[items.length - 1]
      if (e.shiftKey && document.activeElement === first) (e.preventDefault(), last?.focus())
      else if (!e.shiftKey && document.activeElement === last) (e.preventDefault(), first?.focus())
    })
    desktop.addEventListener('change', () => sidebar.dataset.open === 'true' && setOpen(false))

    // ---- Preferences (sidebar collapsed / side, compact header, theme) ----
    const prefState = () => ({
      collapsed: shell.dataset.sidebar === 'collapsed',
      right: shell.dataset.side === 'right',
      compact: shell.dataset.header === 'compact',
      theme: root.dataset.theme === 'dark',
    })
    const syncPrefs = () => {
      const s = prefState()
      document.querySelectorAll('[data-pref]').forEach((el) => {
        el.setAttribute('aria-checked', String(s[el.dataset.pref]))
      })
      document.querySelectorAll('[data-sidebar-toggle]').forEach((el) => {
        el.setAttribute('aria-expanded', String(!s.collapsed))
        const label = el.querySelector('[data-toggle-label]')
        if (label) label.textContent = s.collapsed ? 'Expand sidebar' : 'Collapse sidebar'
      })
      const headerToggle = document.querySelector('[data-header-toggle]')
      headerToggle?.setAttribute('aria-pressed', String(s.compact))
      headerToggle?.setAttribute('title', s.compact ? 'Expand header' : 'Compact header')
    }
    const setPref = (pref, on) => {
      if (pref === 'collapsed') (shell.dataset.sidebar = on ? 'collapsed' : 'expanded'), setCookie('hr-sidebar', shell.dataset.sidebar)
      if (pref === 'right') (shell.dataset.side = on ? 'right' : 'left'), setCookie('hr-sidebar-side', shell.dataset.side)
      if (pref === 'compact') (shell.dataset.header = on ? 'compact' : 'full'), setCookie('hr-header', shell.dataset.header)
      if (pref === 'theme') {
        root.dataset.theme = on ? 'dark' : 'light'
        setCookie('hr-theme', root.dataset.theme)
        window.dispatchEvent(new CustomEvent('hr:theme'))
      }
      hideTip()
      syncPrefs()
    }
    document.querySelectorAll('[data-sidebar-toggle]').forEach((el) =>
      el.addEventListener('click', () => setPref('collapsed', !prefState().collapsed)),
    )
    document.querySelector('[data-header-toggle]')?.addEventListener('click', () => setPref('compact', !prefState().compact))
    document.querySelector('[data-theme-toggle]')?.addEventListener('click', () => setPref('theme', !prefState().theme))
    document.querySelectorAll('[data-pref]').forEach((el) =>
      el.addEventListener('click', () => setPref(el.dataset.pref, el.getAttribute('aria-checked') !== 'true')),
    )
    syncPrefs()

    // ---- Tooltips for the collapsed sidebar (one floating element, placed beside the icon) ----
    const tip = document.getElementById('nav-tip')
    function hideTip() {
      tip.dataset.show = 'false'
    }
    const showTip = (el) => {
      if (!desktop.matches || shell.dataset.sidebar !== 'collapsed' || !el.dataset.tip) return
      tip.textContent = el.dataset.tip
      const r = el.getBoundingClientRect()
      const right = shell.dataset.side === 'right'
      tip.style.top = `${r.top + r.height / 2 - tip.offsetHeight / 2}px`
      tip.style.left = right ? `${r.left - tip.offsetWidth - 10}px` : `${r.right + 10}px`
      tip.dataset.show = 'true'
    }
    sidebar.querySelectorAll('[data-tip]').forEach((el) => {
      el.addEventListener('mouseenter', () => showTip(el))
      el.addEventListener('focus', () => showTip(el))
      el.addEventListener('mouseleave', hideTip)
      el.addEventListener('blur', hideTip)
    })
    sidebar.querySelector('nav')?.addEventListener('scroll', hideTip, { passive: true })

    // ---- Account menu (menu button pattern) ----
    const menuBtn = document.getElementById('user-menu-button')
    const menu = document.getElementById('user-menu')
    if (menuBtn && menu) {
      const items = () => [...menu.querySelectorAll('[role^="menuitem"]')].filter((el) => el.offsetParent !== null)
      let closeTimer
      const isOpen = () => menuBtn.getAttribute('aria-expanded') === 'true'
      const open = (focus = 'first') => {
        clearTimeout(closeTimer)
        syncPrefs()
        menu.hidden = false
        menuBtn.setAttribute('aria-expanded', 'true')
        requestAnimationFrame(() => {
          menu.dataset.open = 'true'
          const list = items()
          if (focus) (focus === 'first' ? list[0] : list[list.length - 1])?.focus()
        })
      }
      const close = (returnFocus = true) => {
        if (!isOpen()) return
        menuBtn.setAttribute('aria-expanded', 'false')
        menu.dataset.open = 'false'
        closeTimer = window.setTimeout(() => (menu.hidden = true), 130)
        if (returnFocus) menuBtn.focus()
      }
      menuBtn.addEventListener('click', () => (isOpen() ? close() : open(null)))
      menuBtn.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowDown' || e.key === 'Enter' || e.key === ' ') (e.preventDefault(), open('first'))
        if (e.key === 'ArrowUp') (e.preventDefault(), open('last'))
      })
      menu.addEventListener('keydown', (e) => {
        const list = items()
        const i = list.indexOf(document.activeElement)
        const move = (n) => (e.preventDefault(), list[(n + list.length) % list.length]?.focus())
        if (e.key === 'ArrowDown') move(i + 1)
        else if (e.key === 'ArrowUp') move(i - 1)
        else if (e.key === 'Home') move(0)
        else if (e.key === 'End') move(list.length - 1)
        else if (e.key === 'Escape') (e.preventDefault(), close())
        else if (e.key === 'Tab') close(false)
      })
      document.addEventListener('click', (e) => {
        if (isOpen() && !menu.contains(e.target) && !menuBtn.contains(e.target)) close(false)
      })
    }

    // ---- "/" focuses the employee search ----
    document.addEventListener('keydown', (e) => {
      const t = e.target
      if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey || t.closest('input, textarea, select, [contenteditable="true"]')) return
      const input = document.getElementById('topbar-q') 
      if (input && input.offsetParent !== null) (e.preventDefault(), input.focus(), input.select())
    })

    // Sessions end 12 hours after sign-in: send the user to the sign-in page when that happens.
    const expiresEl = document.querySelector('[data-session-expires]')
    if (expiresEl) {
      const expires = Number(expiresEl.dataset.sessionExpires)
      const check = () => {
        if (Date.now() >= expires) location.href = '/login?notice=expired'
      }
      setInterval(check, 30_000)
      document.addEventListener('visibilitychange', check)
    }
}
