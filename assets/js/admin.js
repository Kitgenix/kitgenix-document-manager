/* Kitgenix Document Manager – Admin UI */

(function () {
  'use strict';

  function bindTabs() {
    var root = document.getElementById('kitgenix-document-manager-admin-app');
    if (!root) return;

    var triggers = Array.prototype.slice.call(
      root.querySelectorAll('.kitgenix-tab-trigger[data-kitgenix-document-manager-tab]')
    );
    var panels = Array.prototype.slice.call(
      root.querySelectorAll('[data-kitgenix-document-manager-tab-panel]')
    );
    if (!triggers.length || !panels.length) return;

    function getDefaultTab() {
      for (var i = 0; i < triggers.length; i++) {
        if (triggers[i].classList.contains('nav-tab-active')) {
          return triggers[i].getAttribute('data-kitgenix-document-manager-tab') || '';
        }
      }
      return 'documents';
    }

    function getTabFromHash() {
      var hash = window.location && window.location.hash ? window.location.hash : '';
      if (!hash) return '';
      for (var i = 0; i < triggers.length; i++) {
        var href = triggers[i].getAttribute('href') || '';
        if (href && href.indexOf('#') !== -1 && ('#' + href.split('#')[1]) === hash) {
          return triggers[i].getAttribute('data-kitgenix-document-manager-tab') || '';
        }
      }
      var m = hash.match(/-tab-([a-z0-9\-]+)$/i);
      return m && m[1] ? m[1] : '';
    }

    function setActiveTab(tab) {
      tab = tab || getDefaultTab();
      for (var i = 0; i < triggers.length; i++) {
        var t = triggers[i].getAttribute('data-kitgenix-document-manager-tab');
        var isActive = t === tab;
        if (isActive) {
          triggers[i].classList.add('nav-tab-active');
          triggers[i].setAttribute('aria-current', 'page');
        } else {
          triggers[i].classList.remove('nav-tab-active');
          triggers[i].removeAttribute('aria-current');
        }
      }

      for (var j = 0; j < panels.length; j++) {
        var pTab = panels[j].getAttribute('data-kitgenix-document-manager-tab-panel');
        var shouldShow = pTab === tab;
        if (shouldShow) {
          panels[j].removeAttribute('hidden');
          panels[j].style.display = '';
        } else {
          panels[j].setAttribute('hidden', 'hidden');
          panels[j].style.display = 'none';
        }
      }
    }

    for (var i2 = 0; i2 < triggers.length; i2++) {
      triggers[i2].addEventListener('click', function (e) {
        try {
          var tab = this.getAttribute('data-kitgenix-document-manager-tab') || '';
          var href = this.getAttribute('href') || '';
          var hasHash = href.indexOf('#') !== -1;

          if (hasHash) {
            e.preventDefault();
            if (window.history && typeof window.history.pushState === 'function') {
              window.history.pushState(null, document.title, href);
            } else {
              var parts = href.split('#');
              window.location.hash = parts[1] ? '#' + parts[1] : '';
            }
          }

          setActiveTab(tab);
        } catch (_e) {}
      });
    }

    window.addEventListener('hashchange', function () {
      setActiveTab(getTabFromHash() || getDefaultTab());
    });

    setActiveTab(getTabFromHash() || getDefaultTab());
  }

  function setCopiedState(btn) {
    if (!btn) return;
    btn.textContent = btn.getAttribute('data-copied-label') || 'Copied';
    window.setTimeout(function () {
      btn.textContent = btn.getAttribute('data-default-label') || 'Copy';
    }, 3000);
  }

  function slugify(input) {
    input = (input || '').toString().trim().toLowerCase();
    if (!input) return '';
    input = input.normalize ? input.normalize('NFKD') : input;
    input = input.replace(/[\u0300-\u036f]/g, '');
    input = input.replace(/[^a-z0-9\s-]/g, '');
    input = input.replace(/\s+/g, '-');
    input = input.replace(/-+/g, '-');
    input = input.replace(/^-|-$/g, '');
    return input;
  }

  function bindAutoSlug() {
    var titleEl = document.getElementById('kitgenix_dm_title');
    var slugEl = document.getElementById('kitgenix_dm_slug');
    if (!titleEl || !slugEl) return;

    var slugDirty = false;

    slugEl.addEventListener('input', function () {
      slugDirty = (slugEl.value || '').trim().length > 0;
    });

    titleEl.addEventListener('input', function () {
      if (slugDirty) return;
      var s = slugify(titleEl.value);
      if (!s) return;
      slugEl.value = s;
    });
  }

  function bindModalEditor() {
    var modal = document.getElementById('kitgenix-document-manager-edit-modal');
    if (!modal) return;

    var form = document.getElementById('kitgenix-document-manager-edit-form');
    var deleteBtn = document.getElementById('kitgenix-document-manager-modal-delete');
    var deleteAllBtn = document.getElementById('kitgenix-document-manager-delete-all-versions');
    var versionsPanel = document.getElementById('kitgenix-document-manager-versions-panel');
    var versionsList = document.getElementById('kitgenix-document-manager-versions-list');

    var lastVersionsJson = '';

    function escapeText(s) {
      return (s || '').toString();
    }

    function clearNode(node) {
      if (!node) return;
      while (node.firstChild) node.removeChild(node.firstChild);
    }

    function renderVersions(versioningEnabled, versionsJson) {
      if (!versionsPanel || !versionsList) return;

      // Only show when version history is enabled for the doc.
      if (versioningEnabled !== '1') {
        versionsPanel.hidden = true;
        clearNode(versionsList);
        return;
      }

      var versions = [];
      if (versionsJson) {
        try {
          versions = JSON.parse(versionsJson);
          if (!Array.isArray(versions)) versions = [];
        } catch (_e) {
          versions = [];
        }
      }

      versions.sort(function (a, b) {
        var ta = a && typeof a.timestamp === 'number' ? a.timestamp : parseInt((a && a.timestamp) || '0', 10) || 0;
        var tb = b && typeof b.timestamp === 'number' ? b.timestamp : parseInt((b && b.timestamp) || '0', 10) || 0;
        return tb - ta;
      });

      versionsPanel.hidden = false;
      clearNode(versionsList);

      if (!versions.length) {
        var empty = document.createElement('p');
        empty.className = 'kitgenix-muted';
        empty.textContent = 'No versions found.';
        versionsList.appendChild(empty);
        return;
      }

      var ul = document.createElement('ul');
      ul.className = 'kitgenix-versions-list';

      for (var i = 0; i < versions.length; i++) {
        var v = versions[i] || {};

        var li = document.createElement('li');
        li.className = 'kitgenix-versions-item';

        var meta = document.createElement('div');
        meta.className = 'kitgenix-versions-meta';

        var title = document.createElement('div');
        title.className = 'kitgenix-versions-name';
        title.textContent = escapeText(v.fileName || ('Attachment #' + (v.id || ''))) + (v.label ? (' – ' + escapeText(v.label)) : '');
        meta.appendChild(title);

        var sub = document.createElement('div');
        sub.className = 'kitgenix-muted kitgenix-versions-sub';
        var parts = [];
        if (v.size) parts.push(v.size);
        if (v.replacedAt) parts.push(v.replacedAt);
        if (v.uploadedBy) parts.push(escapeText(v.uploadedBy));
        if (v.isCurrent) parts.push('Current');
        sub.textContent = parts.join(' • ');
        meta.appendChild(sub);

        if (v.note) {
          var noteEl = document.createElement('div');
          noteEl.className = 'kitgenix-muted kitgenix-versions-sub';
          noteEl.textContent = escapeText(v.note);
          meta.appendChild(noteEl);
        }

        var actions = document.createElement('div');
        actions.className = 'kitgenix-versions-actions';

        if (v.isCurrent) {
          var currentBadge = document.createElement('span');
          currentBadge.className = 'kitgenix-muted';
          currentBadge.textContent = '';
          actions.appendChild(currentBadge);
        } else {
          if (v.restoreUrl) {
            var restore = document.createElement('a');
            restore.className = 'button button-small';
            restore.href = v.restoreUrl;
            restore.textContent = 'Restore';
            actions.appendChild(restore);
          }

          if (v.deleteUrl) {
            var del = document.createElement('a');
            del.className = 'button button-small button-link-delete';
            del.href = v.deleteUrl;
            del.textContent = 'Delete';
            del.setAttribute('data-kitgenix-version-delete', '1');
            actions.appendChild(del);
          }
        }

        li.appendChild(meta);
        li.appendChild(actions);
        ul.appendChild(li);
      }

      versionsList.appendChild(ul);
    }

    function setMultiSelectValues(select, csv) {
      if (!select) return;

      var values = {};
      var parts = (csv || '').split(',');
      for (var i = 0; i < parts.length; i++) {
        var value = (parts[i] || '').trim();
        if (!value) continue;
        values[value] = true;
      }

      for (var j = 0; j < select.options.length; j++) {
        select.options[j].selected = !!values[select.options[j].value];
      }
    }

    function setShareControls(url) {
      var shareUrlEl = document.getElementById('kitgenix_dm_modal_share_url');
      var shareCopyBtn = document.getElementById('kitgenix_dm_modal_share_url_copy');
      var shareOpenLink = document.getElementById('kitgenix_dm_modal_share_url_open');

      if (shareUrlEl) shareUrlEl.value = url || '';

      if (shareCopyBtn) {
        shareCopyBtn.setAttribute('data-kitgenix-copy', url || '');
        shareCopyBtn.disabled = !url;
      }

      if (shareOpenLink) {
        shareOpenLink.setAttribute('href', url || '#');
        shareOpenLink.style.display = url ? '' : 'none';
      }
    }

    function setAnalyticsValue(id, value) {
      var el = document.getElementById(id);
      if (!el) return;
      el.textContent = value || '0';
    }

    function openModal(data) {
      if (!form) return;

      form.querySelector('input[name="post_id"]').value = data.id || '';
      form.querySelector('input[name="title"]').value = data.title || '';
      form.querySelector('input[name="slug"]').value = data.slug || '';

      var descEl = form.querySelector('textarea[name="description"]');
      if (descEl) descEl.value = data.description || '';

      var visEl = form.querySelector('select[name="visibility"]');
      if (visEl) visEl.value = data.visibility || 'public';

      setMultiSelectValues(form.querySelector('select[name="access_roles[]"]'), data.accessRoles || '');

      var capabilityEl = form.querySelector('input[name="access_capability"]');
      if (capabilityEl) capabilityEl.value = data.accessCapability || '';

      var userIdsEl = form.querySelector('input[name="access_user_ids"]');
      if (userIdsEl) userIdsEl.value = data.accessUserIds || '';

      var shareExpiresEl = form.querySelector('input[name="share_expires_at"]');
      if (shareExpiresEl) shareExpiresEl.value = data.shareExpires || '';
      setShareControls(data.shareUrl || '');

      var shareMaxUsesEl = form.querySelector('input[name="share_max_uses"]');
      if (shareMaxUsesEl) shareMaxUsesEl.value = data.shareMaxUses || '0';

      var shareRevokeEl = document.getElementById('kitgenix_dm_modal_share_revoke');
      if (shareRevokeEl) {
        shareRevokeEl.setAttribute('href', data.shareRevokeUrl || '#');
        shareRevokeEl.style.display = data.shareRevokeUrl ? '' : 'none';
      }

      var shareRegenerateEl = document.getElementById('kitgenix_dm_modal_share_regenerate');
      if (shareRegenerateEl) {
        shareRegenerateEl.setAttribute('href', data.shareRegenerateUrl || '#');
        shareRegenerateEl.style.display = data.shareRegenerateUrl ? '' : 'none';
      }

      var availableFromEl = form.querySelector('input[name="available_from"]');
      if (availableFromEl) availableFromEl.value = data.availableFrom || '';

      var archiveAtEl = form.querySelector('input[name="archive_at"]');
      if (archiveAtEl) archiveAtEl.value = data.archiveAt || '';

      var expiresAtEl = form.querySelector('input[name="expires_at"]');
      if (expiresAtEl) expiresAtEl.value = data.expiresAt || '';

      var scheduleLabelEl = document.getElementById('kitgenix_dm_modal_schedule_status_label');
      if (scheduleLabelEl) scheduleLabelEl.textContent = data.scheduleLabel || 'Active';

      setAnalyticsValue('kitgenix_dm_modal_analytics_views', data.analyticsViews || '0');
      setAnalyticsValue('kitgenix_dm_modal_analytics_downloads', data.analyticsDownloads || '0');
      setAnalyticsValue('kitgenix_dm_modal_analytics_recent_views', data.analyticsRecentViews || '0');
      setAnalyticsValue('kitgenix_dm_modal_analytics_recent_downloads', data.analyticsRecentDownloads || '0');
      setAnalyticsValue('kitgenix_dm_modal_analytics_signed_views', data.analyticsSignedViews || '0');
      setAnalyticsValue('kitgenix_dm_modal_analytics_signed_downloads', data.analyticsSignedDownloads || '0');
      setAnalyticsValue('kitgenix_dm_modal_analytics_last_access', data.analyticsLastAccess || 'No activity yet');

      var verEl = form.querySelector('input[name="versioning_enabled"]');
      if (verEl) verEl.checked = (data.versioning === '1');

      var catEl = form.querySelector('select[name="category_id"]');
      if (catEl) catEl.value = data.categoryId || '0';

      var selectedAttEl = form.querySelector('input[name="selected_attachment_id"]');
      if (selectedAttEl) selectedAttEl.value = '0';
      var mediaLabel = document.getElementById('kitgenix-document-manager-media-selected-modal');
      if (mediaLabel) mediaLabel.textContent = '';

      lastVersionsJson = data.versionsJson || '';
      renderVersions(data.versioning || '0', lastVersionsJson);

      if (deleteBtn) {
        deleteBtn.setAttribute('href', data.deleteUrl || '#');
        deleteBtn.style.display = data.deleteUrl ? '' : 'none';
      }

      if (deleteAllBtn) {
        deleteAllBtn.setAttribute('href', data.deleteAllUrl || '#');
        deleteAllBtn.style.display = data.deleteAllUrl ? '' : 'none';
      }

      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      modal.classList.add('is-open');

      var first = document.getElementById('kitgenix_dm_modal_title');
      if (first) first.focus();
    }

    function closeModal() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      modal.classList.remove('is-open');
    }

    document.addEventListener('click', function (e) {
      var t = e.target;
      if (t && t.nodeType === 3) t = t.parentElement;
      if (!t) return;

      var close = t.closest('[data-kitgenix-modal-close]');
      if (close) {
        e.preventDefault();
        closeModal();
        return;
      }

      var edit = t.closest('[data-kitgenix-edit]');
      if (edit) {
        e.preventDefault();
        openModal({
          id: edit.getAttribute('data-document-id') || '',
          title: edit.getAttribute('data-title') || '',
          slug: edit.getAttribute('data-slug') || '',
          description: edit.getAttribute('data-description') || '',
          visibility: edit.getAttribute('data-visibility') || 'public',
          accessRoles: edit.getAttribute('data-access-roles') || '',
          accessCapability: edit.getAttribute('data-access-capability') || '',
          accessUserIds: edit.getAttribute('data-access-user-ids') || '',
          shareExpires: edit.getAttribute('data-share-expires') || '',
          shareUrl: edit.getAttribute('data-share-url') || '',
          shareMaxUses: edit.getAttribute('data-share-max-uses') || '0',
          shareRevokeUrl: edit.getAttribute('data-share-revoke-url') || '',
          shareRegenerateUrl: edit.getAttribute('data-share-regenerate-url') || '',
          availableFrom: edit.getAttribute('data-available-from') || '',
          archiveAt: edit.getAttribute('data-archive-at') || '',
          expiresAt: edit.getAttribute('data-expires-at') || '',
          scheduleLabel: edit.getAttribute('data-schedule-label') || 'Active',
          analyticsViews: edit.getAttribute('data-analytics-views') || '0',
          analyticsDownloads: edit.getAttribute('data-analytics-downloads') || '0',
          analyticsRecentViews: edit.getAttribute('data-analytics-recent-views') || '0',
          analyticsRecentDownloads: edit.getAttribute('data-analytics-recent-downloads') || '0',
          analyticsSignedViews: edit.getAttribute('data-analytics-signed-views') || '0',
          analyticsSignedDownloads: edit.getAttribute('data-analytics-signed-downloads') || '0',
          analyticsLastAccess: edit.getAttribute('data-analytics-last-access') || 'No activity yet',
          versioning: edit.getAttribute('data-versioning') || '0',
          categoryId: edit.getAttribute('data-category-id') || '0',
          versionsJson: edit.getAttribute('data-versions') || '',
          deleteUrl: edit.getAttribute('data-delete-url') || '',
          deleteAllUrl: edit.getAttribute('data-delete-all-url') || ''
        });
      }

      var deleteVersion = t.closest('[data-kitgenix-version-delete]');
      if (deleteVersion) {
        if (!window.confirm('Delete this version? This cannot be undone.')) {
          e.preventDefault();
          return;
        }
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) {
        closeModal();
      }
    });

    if (deleteBtn) {
      deleteBtn.addEventListener('click', function (e) {
        var href = deleteBtn.getAttribute('href') || '';
        if (!href || href === '#') {
          e.preventDefault();
          return;
        }
        if (!window.confirm('Move this document to the Trash?')) {
          e.preventDefault();
        }
      });
    }

    if (deleteAllBtn) {
      deleteAllBtn.addEventListener('click', function (e) {
        var href = deleteAllBtn.getAttribute('href') || '';
        if (!href || href === '#') {
          e.preventDefault();
          return;
        }
        if (!window.confirm('Delete all old versions for this document? This cannot be undone.')) {
          e.preventDefault();
        }
      });
    }

    // Slug auto-generation in the modal too.
    var titleEl = document.getElementById('kitgenix_dm_modal_title');
    var slugEl = document.getElementById('kitgenix_dm_modal_slug');
    if (titleEl && slugEl) {
      var dirty = false;
      slugEl.addEventListener('input', function () {
        dirty = (slugEl.value || '').trim().length > 0;
      });
      titleEl.addEventListener('input', function () {
        if (dirty) return;
        var s = slugify(titleEl.value);
        if (!s) return;
        slugEl.value = s;
      });
    }

    // Show/hide versions panel when toggling versioning.
    if (form) {
      var versioningToggle = form.querySelector('input[name="versioning_enabled"]');
      if (versioningToggle) {
        versioningToggle.addEventListener('change', function () {
          renderVersions(versioningToggle.checked ? '1' : '0', lastVersionsJson);
        });
      }

      var modalFile = form.querySelector('input[type="file"][name="kitgenix_document_manager_file"]');
      if (modalFile) {
        modalFile.addEventListener('change', function () {
          var selectedAtt = form.querySelector('input[name="selected_attachment_id"]');
          if (selectedAtt) selectedAtt.value = '0';
          var label = document.getElementById('kitgenix-document-manager-media-selected-modal');
          if (label) label.textContent = '';
        });
      }
    }
  }

  function bindMediaPicker() {
    // Requires wp.media (enqueued via wp_enqueue_media()).
    if (typeof window.wp === 'undefined' || !window.wp.media) return;

    var frame = null;

    function open(target) {
      if (!frame) {
        frame = window.wp.media({
          title: 'Select a document file',
          button: { text: 'Use this file' },
          multiple: false
        });
      }

      frame.off('select');
      frame.on('select', function () {
        var selection = frame.state().get('selection');
        var attachment = selection && selection.first ? selection.first() : null;
        if (!attachment) return;

        var model = attachment.toJSON();
        var id = model && model.id ? String(model.id) : '0';
        var label = model && model.filename ? String(model.filename) : (model && model.title ? String(model.title) : '');

        if (target === 'modal') {
          var modalInput = document.getElementById('kitgenix_dm_modal_selected_attachment');
          if (modalInput) modalInput.value = id;
          var modalLabel = document.getElementById('kitgenix-document-manager-media-selected-modal');
          if (modalLabel) modalLabel.textContent = label ? ('Selected: ' + label) : '';
          var modalFile = document.getElementById('kitgenix_dm_modal_file');
          if (modalFile) modalFile.value = '';
        } else {
          var addInput = document.getElementById('kitgenix_dm_selected_attachment');
          if (addInput) addInput.value = id;
          var addLabel = document.getElementById('kitgenix-document-manager-media-selected-add');
          if (addLabel) addLabel.textContent = label ? ('Selected: ' + label) : '';
          var addFile = document.getElementById('kitgenix_dm_file');
          if (addFile) addFile.value = '';
        }
      });

      frame.open();
    }

    document.addEventListener('click', function (e) {
      var t = e.target;
      if (t && t.nodeType === 3) t = t.parentElement;
      if (!t || !t.closest) return;

      var btn = t.closest('.kitgenix-document-manager-media-select');
      if (!btn) return;
      e.preventDefault();

      var target = btn.getAttribute('data-kitgenix-media-target') || 'add';
      open(target === 'modal' ? 'modal' : 'add');
    });

    var addFile = document.getElementById('kitgenix_dm_file');
    if (addFile) {
      addFile.addEventListener('change', function () {
        var addInput = document.getElementById('kitgenix_dm_selected_attachment');
        if (addInput) addInput.value = '0';
        var addLabel = document.getElementById('kitgenix-document-manager-media-selected-add');
        if (addLabel) addLabel.textContent = '';
      });
    }
  }

  function bindCategoryModal() {
    var modal = document.getElementById('kitgenix-document-manager-category-modal');
    if (!modal) return;

    var form = document.getElementById('kitgenix-document-manager-category-form');
    if (!form) return;

    function open(data) {
      form.querySelector('input[name="term_id"]').value = data.termId || '';
      form.querySelector('input[name="name"]').value = data.name || '';
      form.querySelector('input[name="slug"]').value = data.slug || '';

      var parentEl = form.querySelector('select[name="parent_id"]');
      if (parentEl) parentEl.value = data.parentId || '0';

      modal.hidden = false;
      modal.setAttribute('aria-hidden', 'false');
      modal.classList.add('is-open');

      var first = document.getElementById('kitgenix_dm_cat_modal_name');
      if (first) first.focus();
    }

    function close() {
      modal.hidden = true;
      modal.setAttribute('aria-hidden', 'true');
      modal.classList.remove('is-open');
    }

    document.addEventListener('click', function (e) {
      var t = e.target;
      if (t && t.nodeType === 3) t = t.parentElement;
      if (!t) return;

      var closeBtn = t.closest('[data-kitgenix-modal-close]');
      if (closeBtn && !modal.hidden) {
        e.preventDefault();
        close();
        return;
      }

      var edit = t.closest('[data-kitgenix-cat-edit]');
      if (edit) {
        e.preventDefault();
        open({
          termId: edit.getAttribute('data-term-id') || '',
          name: edit.getAttribute('data-name') || '',
          slug: edit.getAttribute('data-slug') || '',
          parentId: edit.getAttribute('data-parent-id') || '0'
        });
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) {
        close();
      }
    });
  }

  function onCopyClick(e) {
    var target = e.target;
    if (target && target.nodeType === 3) target = target.parentElement;
    if (!target || !target.closest) return;

    var btn = target.closest('[data-kitgenix-copy]');
    if (!btn) return;
    e.preventDefault();

    var value = btn.getAttribute('data-kitgenix-copy') || '';
    if (!value) return;

    if (navigator && navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(function () {
        setCopiedState(btn);
      }).catch(function () {
        try {
          var ta1 = document.createElement('textarea');
          ta1.value = value;
          ta1.style.position = 'fixed';
          ta1.style.left = '-9999px';
          document.body.appendChild(ta1);
          ta1.select();
          document.execCommand('copy');
          document.body.removeChild(ta1);
          setCopiedState(btn);
        } catch (err1) {}
      });
      return;
    }

    var ta = document.createElement('textarea');
    ta.value = value;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
      setCopiedState(btn);
    } catch (err) {}
    document.body.removeChild(ta);
  }

  function bindShortcodeBuilder() {
    var wrap = document.getElementById('kitgenix-dm-shortcode-builder');
    if (!wrap) return;

    var output = document.getElementById('kitgenix_dm_sc_output');
    if (!output) return;

    var singlePanel = document.querySelector('[data-kitgenix-dm-sc-panel="single"]');
    var multiPanel = document.querySelector('[data-kitgenix-dm-sc-panel="multiple"]');

    var cfg = (window && window.kitgenixDMAdmin) ? window.kitgenixDMAdmin : {};
    var ajaxUrl = cfg.ajaxUrl || '';
    var nonce = cfg.nonce || '';

    var singleSearch = document.getElementById('kitgenix_dm_sc_search_single');
    var multiSearch = document.getElementById('kitgenix_dm_sc_search_multiple');
    var singleResults = document.getElementById('kitgenix_dm_sc_results_single');
    var multiResults = document.getElementById('kitgenix_dm_sc_results_multiple');
    var singleSlugEl = document.getElementById('kitgenix_dm_sc_single_slug');

    var selectedMultiple = {};

    function escText(s) {
      return (s || '').toString();
    }

    function getMode() {
      var checked = document.querySelector('input[name="kitgenix_dm_sc_mode"]:checked');
      return checked ? checked.value : 'single';
    }

    function setResultsMessage(container, message) {
      if (!container) return;
      container.innerHTML = '';
      var div = document.createElement('div');
      div.className = 'kitgenix-muted';
      div.textContent = message;
      container.appendChild(div);
    }

    function ajaxSearch(query, done) {
      if (!ajaxUrl) {
        done(new Error('Missing ajaxUrl'), null);
        return;
      }

      var url = ajaxUrl + '?action=kitgenix_dm_search_docs&nonce=' + encodeURIComponent(nonce || '') + '&q=' + encodeURIComponent(query || '');

      try {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onreadystatechange = function () {
          if (xhr.readyState !== 4) return;
          if (xhr.status < 200 || xhr.status >= 300) {
            done(new Error('Request failed'), null);
            return;
          }
          try {
            var json = JSON.parse(xhr.responseText || '{}');
            done(null, json);
          } catch (e) {
            done(e, null);
          }
        };
        xhr.send();
      } catch (e) {
        done(e, null);
      }
    }

    function renderSingle(items) {
      if (!singleResults) return;
      singleResults.innerHTML = '';
      if (!items || !items.length) {
        setResultsMessage(singleResults, 'No results');
        return;
      }

      var current = singleSlugEl ? (singleSlugEl.value || '') : '';

      for (var i = 0; i < items.length; i++) {
        var it = items[i] || {};
        var slug = escText(it.slug);
        if (!slug) continue;
        var title = escText(it.title);

        var label = document.createElement('label');
        label.style.display = 'block';
        label.style.marginBottom = '6px';

        var input = document.createElement('input');
        input.type = 'radio';
        input.name = 'kitgenix_dm_sc_single_pick';
        input.value = slug;
        input.checked = (slug === current);

        label.appendChild(input);
        label.appendChild(document.createTextNode(' ' + (title ? (title + ' (' + slug + ')') : slug)));
        singleResults.appendChild(label);
      }
    }

    function renderMultiple(items) {
      if (!multiResults) return;
      multiResults.innerHTML = '';
      if (!items || !items.length) {
        setResultsMessage(multiResults, 'No results');
        return;
      }

      for (var i = 0; i < items.length; i++) {
        var it = items[i] || {};
        var slug = escText(it.slug);
        if (!slug) continue;
        var title = escText(it.title);

        var label = document.createElement('label');
        label.style.display = 'block';
        label.style.marginBottom = '6px';

        var input = document.createElement('input');
        input.type = 'checkbox';
        input.name = 'kitgenix_dm_sc_multi_pick';
        input.value = slug;
        input.checked = !!selectedMultiple[slug];

        label.appendChild(input);
        label.appendChild(document.createTextNode(' ' + (title ? (title + ' (' + slug + ')') : slug)));
        multiResults.appendChild(label);
      }
    }

    function updatePanels() {
      var mode = getMode();

      if (singlePanel) singlePanel.style.display = (mode === 'single') ? '' : 'none';
      if (multiPanel) multiPanel.style.display = (mode === 'multiple') ? '' : 'none';
    }

    function buildShortcode() {
      var mode = getMode();
      var sc = '[kitgenix_document_manager_document slug="my-doc"]';

      if (mode === 'multiple') {
        var slugs = Object.keys(selectedMultiple);
        slugs.sort();
        if (slugs.length) {
          sc = '[kitgenix_document_manager_document slugs="' + slugs.join(',') + '"]';
        } else {
          sc = '[kitgenix_document_manager_document slugs="doc-a,doc-b"]';
        }
      } else {
        var slug = (singleSlugEl && singleSlugEl.value) ? singleSlugEl.value : '';
        if (slug) {
          sc = '[kitgenix_document_manager_document slug="' + slug + '"]';
        }
      }

      output.value = sc;

      var copyBtn = output.parentElement ? output.parentElement.querySelector('[data-kitgenix-copy]') : null;
      if (copyBtn) {
        copyBtn.setAttribute('data-kitgenix-copy', sc);
      }
    }

    var singleTimer = null;
    var multiTimer = null;

    function queueSingleSearch() {
      if (!singleSearch) return;
      var q = (singleSearch.value || '').toString().trim();
      if (singleTimer) window.clearTimeout(singleTimer);
      singleTimer = window.setTimeout(function () {
        if (!q || q.length < 2) {
          setResultsMessage(singleResults, 'Start typing to search.');
          return;
        }
        setResultsMessage(singleResults, 'Searching…');
        ajaxSearch(q, function (err, json) {
          if (err || !json || !json.success) {
            setResultsMessage(singleResults, 'Search failed');
            return;
          }
          renderSingle((json.data && json.data.items) ? json.data.items : []);
        });
      }, 250);
    }

    function queueMultiSearch() {
      if (!multiSearch) return;
      var q = (multiSearch.value || '').toString().trim();
      if (multiTimer) window.clearTimeout(multiTimer);
      multiTimer = window.setTimeout(function () {
        if (!q || q.length < 2) {
          setResultsMessage(multiResults, 'Start typing to search and tick the documents you want.');
          return;
        }
        setResultsMessage(multiResults, 'Searching…');
        ajaxSearch(q, function (err, json) {
          if (err || !json || !json.success) {
            setResultsMessage(multiResults, 'Search failed');
            return;
          }
          renderMultiple((json.data && json.data.items) ? json.data.items : []);
        });
      }, 250);
    }

    wrap.addEventListener('change', function () {
      updatePanels();
      buildShortcode();
    });

    if (singleSearch) {
      singleSearch.addEventListener('input', queueSingleSearch);
    }
    if (multiSearch) {
      multiSearch.addEventListener('input', queueMultiSearch);
    }

    if (singleResults) {
      singleResults.addEventListener('change', function (e) {
        var t = e.target;
        if (!t) return;
        if (t.name === 'kitgenix_dm_sc_single_pick') {
          if (singleSlugEl) singleSlugEl.value = t.value || '';
          buildShortcode();
        }
      });
    }

    if (multiResults) {
      multiResults.addEventListener('change', function (e) {
        var t = e.target;
        if (!t) return;
        if (t.name === 'kitgenix_dm_sc_multi_pick') {
          var v = t.value || '';
          if (!v) return;
          if (t.checked) {
            selectedMultiple[v] = true;
          } else {
            delete selectedMultiple[v];
          }
          buildShortcode();
        }
      });
    }

    updatePanels();
    buildShortcode();
  }

  function bindAccessPresets() {
    document.addEventListener('change', function (e) {
      var select = e.target;
      if (!select || select.getAttribute('data-kitgenix-access-preset') !== '1') return;

      var preset = select.value || '';
      if (!preset) return;

      var form = select.closest ? select.closest('form') : null;
      if (!form) return;

      var visibilityEl = form.querySelector('select[name="visibility"]');
      var rolesEl = form.querySelector('select[name="access_roles[]"]');
      var capabilityEl = form.querySelector('input[name="access_capability"]');
      var userIdsEl = form.querySelector('input[name="access_user_ids"]');

      function clearRoles() {
        if (!rolesEl) return;
        for (var i = 0; i < rolesEl.options.length; i++) {
          rolesEl.options[i].selected = false;
        }
      }

      function selectRole(roleValue) {
        if (!rolesEl) return;
        for (var i = 0; i < rolesEl.options.length; i++) {
          if (rolesEl.options[i].value === roleValue) {
            rolesEl.options[i].selected = true;
          }
        }
      }

      if (preset === 'public') {
        if (visibilityEl) visibilityEl.value = 'public';
        clearRoles();
        if (capabilityEl) capabilityEl.value = '';
        if (userIdsEl) userIdsEl.value = '';
      } else if (preset === 'logged_in') {
        if (visibilityEl) visibilityEl.value = 'private';
        clearRoles();
        if (capabilityEl) capabilityEl.value = '';
        if (userIdsEl) userIdsEl.value = '';
      } else if (preset === 'admins_only') {
        if (visibilityEl) visibilityEl.value = 'restricted';
        clearRoles();
        selectRole('administrator');
        if (capabilityEl) capabilityEl.value = '';
        if (userIdsEl) userIdsEl.value = '';
      }

      // Reset back to the placeholder so re-choosing the same preset still fires "change".
      select.value = '';
    });
  }

  function bindDocsSearch() {
    var input = document.getElementById('kitgenix-document-manager-docs-search');
    var table = document.getElementById('kitgenix-document-manager-docs-table');
    if (!input || !table) return;

    var clearBtn = document.getElementById('kitgenix-document-manager-docs-clear');
    var countEl = document.getElementById('kitgenix-document-manager-docs-count');
    var emptyEl = document.getElementById('kitgenix-document-manager-docs-no-results');
    var filterSelects = Array.prototype.slice.call(document.querySelectorAll('[data-kitgenix-docs-filter]'));

    var tbody = table.querySelector('tbody');
    if (!tbody) return;

    function rows() {
      return Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    }

    function norm(s) {
      return (s || '').toString().trim().toLowerCase();
    }

    function activeFilters() {
      var active = {};
      for (var i = 0; i < filterSelects.length; i++) {
        var sel = filterSelects[i];
        var key = sel.getAttribute('data-kitgenix-docs-filter');
        var val = (sel.value || '').toString();
        if (key && val) active[key] = val;
      }
      return active;
    }

    function rowMatchesFilters(row, filters) {
      if (filters.status && row.getAttribute('data-kitgenix-status') !== filters.status) return false;
      if (filters.visibility && row.getAttribute('data-kitgenix-visibility') !== filters.visibility) return false;
      if (filters.filetype && row.getAttribute('data-kitgenix-filetype') !== filters.filetype) return false;
      return true;
    }

    function update() {
      var q = norm(input.value);
      var filters = activeFilters();
      var hasFilters = !!(filters.status || filters.visibility || filters.filetype);
      var all = rows();
      var visible = 0;

      for (var i = 0; i < all.length; i++) {
        var r = all[i];
        var blob = norm(r.getAttribute('data-kitgenix-search') || r.textContent || '');
        var show = (!q || (blob.indexOf(q) !== -1)) && rowMatchesFilters(r, filters);
        r.style.display = show ? '' : 'none';
        if (show) visible++;
      }

      if (clearBtn) {
        clearBtn.style.display = (q || hasFilters) ? '' : 'none';
      }

      if (countEl) {
        if (!q && !hasFilters) {
          countEl.textContent = '';
        } else {
          countEl.textContent = visible + ' / ' + all.length;
        }
      }

      if (emptyEl) {
        emptyEl.hidden = (visible !== 0);
      }
    }

    input.addEventListener('input', update);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        input.value = '';
        update();
        input.blur();
      }
    });

    for (var s = 0; s < filterSelects.length; s++) {
      filterSelects[s].addEventListener('change', update);
    }

    if (clearBtn) {
      clearBtn.addEventListener('click', function () {
        input.value = '';
        for (var s2 = 0; s2 < filterSelects.length; s2++) {
          filterSelects[s2].value = '';
        }
        update();
        input.focus();
      });
    }

    update();
  }

  function bindDocumentBulkTools() {
    var form = document.querySelector('[data-kitgenix-bulk-actions-form="1"]');
    if (!form) return;

    var selectAll = document.querySelector('[data-kitgenix-select-all="1"]');
    var actionSelect = document.querySelector('[data-kitgenix-bulk-action-select="1"]');
    var categorySelect = document.getElementById('kitgenix-document-manager-bulk-category');
    var submitBtn = document.querySelector('[data-kitgenix-bulk-submit="1"]');
    var countEl = document.getElementById('kitgenix-document-manager-selection-count');
    var searchInput = document.getElementById('kitgenix-document-manager-docs-search');
    var clearBtn = document.getElementById('kitgenix-document-manager-docs-clear');

    function checkboxes() {
      return Array.prototype.slice.call(form.querySelectorAll('[data-kitgenix-document-checkbox="1"]'));
    }

    function visibleCheckboxes() {
      return checkboxes().filter(function (checkbox) {
        var row = checkbox.closest ? checkbox.closest('tr') : null;
        return !row || row.style.display !== 'none';
      });
    }

    function selectedCount() {
      return checkboxes().filter(function (checkbox) {
        return checkbox.checked;
      }).length;
    }

    function selectedVisibleCount() {
      return visibleCheckboxes().filter(function (checkbox) {
        return checkbox.checked;
      }).length;
    }

    function updateState() {
      var totalSelected = selectedCount();
      var visible = visibleCheckboxes();
      var visibleSelected = selectedVisibleCount();
      var needsCategory = actionSelect && actionSelect.value === 'assign_category';
      var canSubmit = !!(actionSelect && actionSelect.value) && totalSelected > 0;

      if (countEl) {
        if (!totalSelected) {
          countEl.textContent = 'No documents selected.';
        } else if (totalSelected === 1) {
          countEl.textContent = '1 document selected.';
        } else {
          countEl.textContent = totalSelected + ' documents selected.';
        }
      }

      if (selectAll) {
        selectAll.disabled = !visible.length;
        selectAll.checked = !!visible.length && visibleSelected === visible.length;
        selectAll.indeterminate = visibleSelected > 0 && visibleSelected < visible.length;
      }

      if (categorySelect) {
        categorySelect.disabled = !needsCategory;
        if (!needsCategory) {
          categorySelect.value = '0';
        }
      }

      if (needsCategory) {
        canSubmit = canSubmit && categorySelect && categorySelect.value !== '0';
      }

      if (submitBtn) {
        submitBtn.disabled = !canSubmit;
      }
    }

    if (selectAll) {
      selectAll.addEventListener('change', function () {
        var visible = visibleCheckboxes();
        for (var i = 0; i < visible.length; i++) {
          visible[i].checked = !!selectAll.checked;
        }
        updateState();
      });
    }

    form.addEventListener('change', function (e) {
      var t = e.target;
      if (!t) return;

      if (
        t.getAttribute('data-kitgenix-document-checkbox') === '1' ||
        t.getAttribute('data-kitgenix-bulk-action-select') === '1' ||
        t.id === 'kitgenix-document-manager-bulk-category'
      ) {
        updateState();
      }
    });

    form.addEventListener('submit', function (e) {
      var action = actionSelect ? (actionSelect.value || '') : '';
      var totalSelected = selectedCount();

      if (!action || totalSelected <= 0) {
        e.preventDefault();
        updateState();
        return;
      }

      if (action === 'assign_category' && categorySelect && categorySelect.value === '0') {
        e.preventDefault();
        categorySelect.focus();
        updateState();
        return;
      }

      if (action === 'delete' && !window.confirm('Move the selected documents to the Trash?')) {
        e.preventDefault();
        return;
      }

      if (action === 'visibility_public' && !window.confirm('Set the selected documents to Public? This may include documents that are currently Private or Restricted, and will remove those access controls.')) {
        e.preventDefault();
        return;
      }

      if (action === 'archive_now' && !window.confirm('Archive the selected documents now? They will stop appearing on the frontend and via stable links until un-archived.')) {
        e.preventDefault();
      }
    });

    if (searchInput) {
      searchInput.addEventListener('input', updateState);
    }

    if (clearBtn) {
      clearBtn.addEventListener('click', updateState);
    }

    updateState();
  }

  function bindBulkUploadDropzone() {
    var form = document.querySelector('[data-kitgenix-bulk-upload-form="1"]');
    if (!form) return;

    var dropzone = document.querySelector('[data-kitgenix-bulk-dropzone="1"]');
    var input = document.querySelector('[data-kitgenix-bulk-files-input="1"]');
    var list = document.querySelector('[data-kitgenix-bulk-files-list="1"]');
    if (!dropzone || !input || !list) return;

    function filesArray(fileList) {
      var files = [];
      if (!fileList || !fileList.length) return files;

      for (var i = 0; i < fileList.length; i++) {
        files.push(fileList[i]);
      }

      return files;
    }

    function renderSelectedFiles() {
      var files = filesArray(input.files);
      list.innerHTML = '';

      if (!files.length) {
        var empty = document.createElement('p');
        empty.className = 'kitgenix-muted';
        empty.textContent = 'No files selected yet.';
        list.appendChild(empty);
        return;
      }

      var summary = document.createElement('p');
      summary.className = 'kitgenix-document-manager-selected-files__summary';
      summary.textContent = files.length === 1 ? '1 file ready to upload.' : files.length + ' files ready to upload.';
      list.appendChild(summary);

      var listEl = document.createElement('ul');
      listEl.className = 'kitgenix-document-manager-selected-files__list';

      for (var i = 0; i < files.length; i++) {
        var file = files[i];
        var item = document.createElement('li');
        var name = file && file.name ? file.name : 'Untitled file';
        item.textContent = name;
        listEl.appendChild(item);
      }

      list.appendChild(listEl);
    }

    function setFiles(fileList) {
      if (!fileList || !fileList.length) return false;

      try {
        var dataTransfer = new window.DataTransfer();
        for (var i = 0; i < fileList.length; i++) {
          dataTransfer.items.add(fileList[i]);
        }
        input.files = dataTransfer.files;
        renderSelectedFiles();
        return true;
      } catch (_e) {
        return false;
      }
    }

    function activateDropzone() {
      dropzone.classList.add('is-dragover');
    }

    function deactivateDropzone() {
      dropzone.classList.remove('is-dragover');
    }

    dropzone.addEventListener('click', function () {
      input.click();
    });

    dropzone.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        input.click();
      }
    });

    dropzone.addEventListener('dragenter', function (e) {
      e.preventDefault();
      activateDropzone();
    });

    dropzone.addEventListener('dragover', function (e) {
      e.preventDefault();
      activateDropzone();
    });

    dropzone.addEventListener('dragleave', function (e) {
      var related = e.relatedTarget;
      if (related && dropzone.contains && dropzone.contains(related)) return;
      deactivateDropzone();
    });

    dropzone.addEventListener('drop', function (e) {
      e.preventDefault();
      deactivateDropzone();

      var files = e.dataTransfer ? e.dataTransfer.files : null;
      if (files && files.length) {
        if (!setFiles(files)) {
          renderSelectedFiles();
        }
      }
    });

    input.addEventListener('change', renderSelectedFiles);

    form.addEventListener('submit', function (e) {
      if (!input.files || !input.files.length) {
        e.preventDefault();
        input.focus();
      }
    });

    renderSelectedFiles();
  }

  function boot() {
    document.addEventListener('click', onCopyClick);
    bindTabs();
    bindAutoSlug();
    bindAccessPresets();
    bindDocsSearch();
    bindDocumentBulkTools();
    bindBulkUploadDropzone();
    bindMediaPicker();
    bindModalEditor();
    bindCategoryModal();
    bindShortcodeBuilder();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
