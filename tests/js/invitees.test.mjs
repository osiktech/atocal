import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

const DEBOUNCE_MS = 250;
const SEARCH_URL = '/apps/atocal/contact/search';
const BOB = { name: 'Bob', email: 'bob@example.com' };
const ZOE = { name: 'Zoe', email: 'zoe@example.com' };

let input;
let list;

function response(contacts) {
  return Promise.resolve({
    ok: true,
    json: () => Promise.resolve(contacts),
  });
}

/* Promise we can settle manually, to emulate an in-flight request */
function pendingResponse() {
  let resolve;
  const promise = new Promise((res) => {
    resolve = res;
  });

  return { promise, resolve };
}

function type(fragment) {
  input.value = fragment;
  input.dispatchEvent(new Event('input', { bubbles: true }));
}

/* Let the debounce fire and the promise chain settle */
async function settle(ms = DEBOUNCE_MS) {
  await vi.advanceTimersByTimeAsync(ms);
}

beforeAll(async () => {
  document.body.innerHTML =
    '<span><input id="atocal-attendees" data-search-url="' + SEARCH_URL + '"'
    + ' role="combobox" aria-autocomplete="list"'
    + ' aria-controls="atocal-suggestions" aria-expanded="false"></span>';

  await import('../../js/invitees.js');

  input = document.getElementById('atocal-attendees');
  list = document.querySelector('.atocal-suggestions');
});

beforeEach(() => {
  vi.useFakeTimers();
  vi.stubGlobal('fetch', vi.fn());
});

afterEach(async () => {
  /* Empty fragment hides the list and cancels pending debounces */
  type('');
  await settle(0);
  vi.useRealTimers();
  vi.unstubAllGlobals();
});

describe('invitee suggestions', () => {
  it('marks the input as a combobox controlling the list', () => {
    expect(input.getAttribute('role')).toBe('combobox');
    expect(input.getAttribute('aria-autocomplete')).toBe('list');

    /* aria-controls must resolve to the list built by the widget */
    const controlled = document.getElementById(
      input.getAttribute('aria-controls')
    );
    expect(controlled).toBe(list);
    expect(input.getAttribute('aria-expanded')).toBe('false');
  });

  it('renders matching contacts', async () => {
    fetch.mockReturnValue(response([BOB]));

    type('bo');
    await settle();

    expect(fetch).toHaveBeenCalledTimes(1);
    expect(list.hidden).toBe(false);
    expect(list.querySelectorAll('[role="option"]')).toHaveLength(1);
    expect(list.textContent).toContain('bob@example.com');
    expect(input.getAttribute('aria-expanded')).toBe('true');
  });

  it('ignores responses that arrive after the field was cleared', async () => {
    const inFlight = pendingResponse();
    fetch.mockReturnValue(inFlight.promise);

    type('bo');
    await settle();

    /* User clears the field while the request is still in flight */
    type('');

    inFlight.resolve({
      ok: true,
      json: () => Promise.resolve([BOB]),
    });
    await settle(0);

    expect(list.hidden).toBe(true);
    expect(list.children).toHaveLength(0);
    expect(input.getAttribute('aria-expanded')).toBe('false');
  });

  it('ignores responses that arrive after a contact was picked', async () => {
    const second = pendingResponse();
    fetch
      .mockReturnValueOnce(response([BOB]))
      .mockReturnValueOnce(second.promise);

    type('bo');
    await settle();
    expect(list.hidden).toBe(false);

    /* Typing more triggers a second request */
    type('bob');
    await settle();

    /* User picks the visible suggestion while the request is in flight */
    list.querySelector('button').dispatchEvent(
      new MouseEvent('mousedown', { bubbles: true, cancelable: true })
    );

    expect(input.value).toBe('bob@example.com, ');
    expect(list.hidden).toBe(true);

    /* The stale response must not reopen the list */
    second.resolve({
      ok: true,
      json: () => Promise.resolve([ZOE]),
    });
    await settle(0);

    expect(list.hidden).toBe(true);
    expect(list.children).toHaveLength(0);
  });

  it('searches only the last whitespace-separated fragment', async () => {
    fetch.mockReturnValue(response([BOB]));

    type('alice@example.com bo');
    await settle();

    expect(fetch).toHaveBeenCalledWith(
      SEARCH_URL + '?query=bo',
      expect.objectContaining({ headers: { Accept: 'application/json' } })
    );
  });

  it('keeps whitespace-separated entries when picking a contact', async () => {
    fetch.mockReturnValue(response([BOB]));

    type('alice@example.com bo');
    await settle();

    list.querySelector('button').dispatchEvent(
      new MouseEvent('mousedown', { bubbles: true, cancelable: true })
    );

    expect(input.value).toBe('alice@example.com bob@example.com, ');
  });
});
