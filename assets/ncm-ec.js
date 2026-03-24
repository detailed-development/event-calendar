document.addEventListener("DOMContentLoaded", () => {
  const roots = document.querySelectorAll("[data-ncm-ec]");
  if (!roots.length) return;

  roots.forEach((root) => {
    let settings;
    try {
      settings = JSON.parse(root.getAttribute("data-ncm-ec") || "{}");
    } catch (e) {
      return;
    }

    const calEl = root.querySelector(".ncm-ec-calendar");
    const overlay = root.querySelector(".ncm-ec-popup-overlay");
    const popup = root.querySelector(".ncm-ec-popup");
    if (!calEl || !overlay || !popup) return;

    // Popup elements
    const closeBtn = root.querySelector(".ncm-ec-popup-close");
    const imgWrap = root.querySelector(".ncm-ec-popup-image");
    const img = imgWrap ? imgWrap.querySelector("img") : null;
    const titleEl = root.querySelector(".ncm-ec-title");
    const descEl = root.querySelector(".ncm-ec-desc");
    const timeEl = root.querySelector(".ncm-ec-time");
    const dateLabelEl = root.querySelector(".ncm-ec-date-label");
    const orgEl = root.querySelector(".ncm-ec-organizer");
    const locEl = root.querySelector(".ncm-ec-location");
    const actionsWrap = root.querySelector(".ncm-ec-actions");
    const linkEl = root.querySelector(".ncm-ec-link");

    function openPopup() {
      overlay.hidden = false;
      document.documentElement.style.overflow = "hidden";
    }

    function closePopup() {
      overlay.hidden = true;
      document.documentElement.style.overflow = "";
    }

    // close interactions
    closeBtn && closeBtn.addEventListener("click", closePopup);
    overlay.addEventListener("click", (e) => {
      if (e.target === overlay) closePopup();
    });
    if (!document.documentElement.dataset.ncmEcEscBound) {
      document.documentElement.dataset.ncmEcEscBound = "1";
      document.addEventListener("keydown", (e) => {
        document.querySelectorAll(".ncm-ec-popup-overlay:not([hidden])").forEach((overlay) => {
          if (e.key === "Escape") overlay.hidden = true;
        });
      });
    }


    if (!settings.hasTEC) return;

    if (!window.FullCalendar || !FullCalendar.Calendar) return;

    // prevent double-init (Elementor rerenders, etc.)
    if (calEl._ncmCalendar) {
      calEl._ncmCalendar.destroy();
      calEl._ncmCalendar = null;
    }

    let activeEventsController = null;

    const calendar = new FullCalendar.Calendar(calEl, {
      initialView: settings.initialView || "dayGridMonth",
      firstDay: Number.isFinite(settings.firstDay) ? settings.firstDay : 0,
      height: "700",
      expandRows: true,
      fixedWeekCount: false,

      showNonCurrentDates: false,

      headerToolbar: { left: "prev,today,next", center: "title", right: "" },

      buttonText: {
        today: "TODAY",
        month: "MONTH",
        week: "WEEK",
        day: "DAY",
        list: "LIST",
      },


      eventTimeFormat: {
        hour: "numeric",
        minute: "2-digit",
        meridiem: "short",
      },
      events: async (info, success, failure) => {
        try {
          // cancel any in-flight request
          if (activeEventsController) activeEventsController.abort();
          activeEventsController = new AbortController();

          const url = new URL(settings.restUrl, window.location.origin);
          url.searchParams.set("start", info.startStr);
          url.searchParams.set("end", info.endStr);

          const res = await fetch(url.toString(), {
            headers: { "X-WP-Nonce": settings.nonce || "" },
            credentials: "same-origin",
            signal: activeEventsController.signal,
          });

          if (!res.ok) throw new Error("Event fetch failed");
          const data = await res.json();
          success(data);
        } catch (err) {
          // ignore abort errors (they’re expected when clicking fast)
          if (err.name === "AbortError") return;
          console.warn(err);
          failure(err);
        }
      },

      eventClick: (arg) => {
        // prevent browser navigating away
        arg.jsEvent.preventDefault();

        const ev = arg.event;
        const p = ev.extendedProps || {};

        // Title
        if (titleEl) titleEl.textContent = ev.title || "";

        // Date label + time
        if (dateLabelEl) dateLabelEl.textContent = p.dateLabel || "Date";

        // Use FullCalendar dates for time display
        let timeText = "";
        if (ev.allDay) {
          timeText = "All Day";
        } else {
          // FullCalendar provides a nice string sometimes, but we can format simply:
          const start = ev.start ? ev.start.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }) : "";
          const end = ev.end ? ev.end.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }) : "";
          timeText = end ? `${start} - ${end}` : start;
        }
        if (timeEl) timeEl.textContent = timeText;

        // Organizer / location
        if (orgEl) orgEl.textContent = p.organizer || "";
        if (locEl) {
          // If mapUrl exists, make it a link
          if (p.mapUrl) {
            locEl.innerHTML = "";
            const a = document.createElement("a");
            a.href = p.mapUrl;
            a.target = "_blank";
            a.rel = "noopener noreferrer";
            a.textContent = p.location || "";
            a.className = "ncm-ec-location-link";
            locEl.appendChild(a);
          } else {
            locEl.textContent = p.location || "";
          }
        }

        // Description HTML (already sanitized server-side via wp_kses_post)
        if (descEl) descEl.innerHTML = p.desc || "";

        // Image
        if (imgWrap && img) {
          if (p.image) {
            img.src = p.image;
            img.alt = ev.title || "Event image";
            imgWrap.hidden = false;
          } else {
            imgWrap.hidden = true;
            img.removeAttribute("src");
          }
        }

        // Optional action link (event URL)
        if (actionsWrap && linkEl) {
          actionsWrap.hidden = true;
          linkEl.textContent = "";
          linkEl.href = "";

          if (ev.url) {
            actionsWrap.hidden = false;
            linkEl.href = ev.url;
            linkEl.textContent = "View Event";
          }
        }

        openPopup();
      },
    });

    calendar.render();
    calEl._ncmCalendar = calendar;

    // ---- View dropdown (plugin-owned) ----
    const toolbar = calEl.querySelector(".fc-header-toolbar");
    const rightChunk = toolbar?.querySelector(".fc-toolbar-chunk:last-child");
    if (rightChunk) {
      const viewWrap = document.createElement("div");
      viewWrap.className = "ncm-ec-view";

      // Read enabled views from PHP settings
      const enabledViewsRaw = Array.isArray(settings.enabledViews)
        ? settings.enabledViews
        : ["month", "week", "day", "list"];

      const viewMap = {
        month: { fc: "dayGridMonth", label: "MONTH" },
        week:  { fc: "timeGridWeek", label: "WEEK" },
        day:   { fc: "timeGridDay", label: "DAY" },
        list:  { fc: "listMonth", label: "LIST" },
      };

      // Filter out invalid tokens
      const enabledViews = enabledViewsRaw.filter((k) => viewMap[k]);

      // If there’s only one view enabled, you can skip rendering the dropdown
      // (uncomment if you want that behavior)
      // if (enabledViews.length <= 1) return;

      const menuItems = enabledViews
        .map((key) => {
          const v = viewMap[key];
          return `<button type="button" class="ncm-ec-view-item" role="menuitem" data-view="${v.fc}">${v.label}</button>`;
        })
        .join("");

      viewWrap.innerHTML = `
        <button type="button" class="ncm-ec-view-btn" aria-haspopup="menu" aria-expanded="false">
          <span class="ncm-ec-view-label"></span>
          <span aria-hidden="true" class="ncm-ec-caret">▾</span>
        </button>
        <div class="ncm-ec-view-menu" role="menu">
          ${menuItems}
        </div>
      `;

      rightChunk.appendChild(viewWrap);

      const btn = viewWrap.querySelector(".ncm-ec-view-btn");
      const label = viewWrap.querySelector(".ncm-ec-view-label");
      const menu = viewWrap.querySelector(".ncm-ec-view-menu");

      const viewToLabel = Object.values(viewMap).reduce((acc, v) => {
        acc[v.fc] = v.label;
        return acc;
      }, {});

      // Default label: current view label, or first enabled view label
      const defaultLabel = enabledViews[0] ? viewMap[enabledViews[0]].label : "MONTH";

      const setLabel = () => {
        const v = calendar.view.type;
        if (label) label.textContent = viewToLabel[v] || defaultLabel;
      };
      setLabel();

      const closeMenu = () => {
        viewWrap.classList.remove("is-open");
        btn?.setAttribute("aria-expanded", "false");
      };
      const openMenu = () => {
        viewWrap.classList.add("is-open");
        btn?.setAttribute("aria-expanded", "true");
      };

      btn?.addEventListener("click", (e) => {
        e.preventDefault();
        viewWrap.classList.contains("is-open") ? closeMenu() : openMenu();
      });

      menu?.addEventListener("click", (e) => {
        const item = e.target.closest(".ncm-ec-view-item");
        if (!item) return;
        calendar.changeView(item.dataset.view);
        closeMenu();
        setLabel();
      });

      document.addEventListener("click", (e) => {
        if (!viewWrap.contains(e.target)) closeMenu();
      });

      calendar.on("datesSet", () => setLabel());
    }


  });
});
