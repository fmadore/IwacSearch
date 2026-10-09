/**
 * Copy-to-clipboard with a legacy fallback.
 *
 * Nothing search-specific lives here — it was ~45 lines of DOM plumbing
 * sitting in the middle of App.svelte's search state.
 *
 * The fallback matters for real visitors: `navigator.clipboard` is only
 * available in secure contexts, so an install served over plain HTTP (or an
 * older in-app WebView) would otherwise silently do nothing when the user
 * clicks "copy link".
 */

/** execCommand fallback for non-secure contexts / older WebViews. */
function fallbackCopy(text: string): boolean {
  try {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return ok;
  } catch {
    return false;
  }
}

/** Copy `text`, resolving to whether it worked. Never rejects. */
export async function copyText(text: string): Promise<boolean> {
  if (navigator.clipboard?.writeText) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch {
      // Permission denied / not focused — fall through to execCommand.
    }
  }
  return fallbackCopy(text);
}

/**
 * "Copy" button state: call `copy()`, and `status` is 'copied' — or
 * 'failed', which used to be silence: the button simply did nothing — for
 * `resetMs`, so the button can swap its label instead of raising a toast.
 * Repeated clicks restart the timer rather than stacking.
 */
export function createCopyState(resetMs = 2000) {
  let status = $state<'idle' | 'copied' | 'failed'>('idle');
  let timer: number | null = null;

  return {
    get status(): 'idle' | 'copied' | 'failed' {
      return status;
    },
    get copied(): boolean {
      return status === 'copied';
    },
    async copy(text: string): Promise<void> {
      status = (await copyText(text)) ? 'copied' : 'failed';
      if (timer !== null) window.clearTimeout(timer);
      timer = window.setTimeout(() => {
        status = 'idle';
        timer = null;
      }, resetMs);
    },
  };
}
