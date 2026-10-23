/**
 * Live contact search for the invitee input.
 *
 * A picked suggestion replaces the fragment after the last
 * separator, so a comma-separated list keeps working:
 *
 *   "alice@x.com, bo"  ->  pick "Bob"  ->  "alice@x.com, bob@x.com, "
 *
 * Without JavaScript the input falls back to the static
 * datalist rendered by the server.
 */
(function () {
  'use strict';

  var DEBOUNCE_MS = 250;
  /* Must match the server's attendee parsing: whitespace, comma and
   * semicolon all separate entries. */
  var SEPARATOR = /[\s;,]+/;

  var input = document.getElementById('atocal-attendees');

  if (!input) {
    return;
  }

  var searchUrl = input.dataset.searchUrl;

  if (!searchUrl) {
    return;
  }

  var list = document.createElement('ul');
  list.id = 'atocal-suggestions';
  list.className = 'atocal-suggestions';
  list.hidden = true;
  list.setAttribute('role', 'listbox');

  /* The wrapper span provides position: relative */
  input.parentNode.appendChild(list);

  /* The native datalist popup would double the custom one */
  input.removeAttribute('list');

  var debounceTimer = null;
  var requestSeq = 0;
  var suggestions = [];
  var activeIndex = -1;

  function fragment() {
    var parts = input.value.split(SEPARATOR);
    return parts[parts.length - 1].trim();
  }

  function hide() {
    requestSeq++;
    list.hidden = true;
    list.replaceChildren();
    suggestions = [];
    activeIndex = -1;
    input.setAttribute('aria-expanded', 'false');
    input.removeAttribute('aria-activedescendant');
  }

  function commit(email) {
    var parts = input.value.split(/([\s;,]+)/);
    parts.pop();
    var prefix = parts.join('').replace(/\s+$/, '');

    input.value = prefix === '' ? email + ', ' : prefix + ' ' + email + ', ';
    hide();
    input.focus();
  }

  function moveSelection(offset) {
    activeIndex = (activeIndex + offset + suggestions.length) % suggestions.length;

    list.childNodes.forEach(function (option, i) {
      option.classList.toggle('atocal-active', i === activeIndex);
    });
    input.setAttribute('aria-activedescendant', 'atocal-suggestion-' + activeIndex);
  }

  function render(contacts) {
    hide();

    if (contacts.length === 0) {
      return;
    }
    suggestions = contacts;

    contacts.forEach(function (contact, i) {
      var option = document.createElement('li');
      option.id = 'atocal-suggestion-' + i;
      option.setAttribute('role', 'option');

      var button = document.createElement('button');
      button.type = 'button';
      button.tabIndex = -1;
      button.addEventListener('mousedown', function (event) {
        /* keep focus on the input */
        event.preventDefault();
        commit(contact.email);
      });

      var name = document.createElement('span');
      name.className = 'atocal-suggestion-name';
      name.textContent = contact.name === '' ? contact.email : contact.name;

      var email = document.createElement('span');
      email.className = 'atocal-suggestion-email';
      email.textContent = contact.email;

      button.appendChild(name);
      button.appendChild(email);
      option.appendChild(button);
      list.appendChild(option);
    });

    list.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  function search(query) {
    var seq = ++requestSeq;

    fetch(searchUrl + '?query=' + encodeURIComponent(query), {
      headers: { Accept: 'application/json' }
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('search failed');
        }
        return response.json();
      })
      .then(function (contacts) {
        if (seq === requestSeq) {
          render(Array.isArray(contacts) ? contacts : []);
        }
      })
      .catch(function () {
        if (seq === requestSeq) {
          hide();
        }
      });
  }

  input.addEventListener('input', function () {
    clearTimeout(debounceTimer);

    var query = fragment();

    if (query === '') {
      hide();
      return;
    }
    debounceTimer = setTimeout(function () {
      search(query);
    }, DEBOUNCE_MS);
  });

  input.addEventListener('keydown', function (event) {
    if (list.hidden) {
      return;
    }
    switch (event.key) {
      case 'ArrowDown':
        moveSelection(1);
        event.preventDefault();
        break;
      case 'ArrowUp':
        moveSelection(-1);
        event.preventDefault();
        break;
      case 'Enter':
        if (activeIndex >= 0) {
          commit(suggestions[activeIndex].email);
          event.preventDefault();
        }
        break;
      case 'Escape':
        hide();
        break;
    }
  });

  document.addEventListener('click', function (event) {
    if (!list.hidden && event.target !== input && !list.contains(event.target)) {
      hide();
    }
  });
})();
