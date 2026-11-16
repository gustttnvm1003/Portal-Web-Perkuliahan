(() => {
  const root = document.documentElement;
  const ua = navigator.userAgent;
  const reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const fine = window.matchMedia("(pointer: fine)").matches;

  // iPadOS desktop-mode detection
  if (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1) {
    root.classList.remove("dev-desktop", "dev-mobile");
    root.classList.add("dev-ios");
  }

  // --- Scroll / mount reveal ---
  const markReveal = () => {
    document.querySelectorAll(".grid > *, .stack > *, .content > h1, .content > .lede, .content > .kicker, .content > .section, .content > h2, .card, .split > *").forEach((el, i) => {
      if (el.classList.contains("reveal")) return;
      el.classList.add("reveal");
      el.style.transitionDelay = `${Math.min(i * 0.04, 0.35)}s`;
    });
  };
  markReveal();

  if (!reduce && "IntersectionObserver" in window) {
    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((e) => {
          if (e.isIntersecting) {
            e.target.classList.add("is-in");
            io.unobserve(e.target);
          }
        });
      },
      { threshold: 0.12, rootMargin: "0px 0px -8% 0px" }
    );
    document.querySelectorAll(".reveal").forEach((el) => io.observe(el));
  } else {
    document.querySelectorAll(".reveal").forEach((el) => el.classList.add("is-in"));
  }

  // --- 3D tilt + sheen on cards ---
  if (!reduce && fine) {
    document.querySelectorAll("a.card").forEach((card) => {
      card.addEventListener("mousemove", (e) => {
        if (window.innerWidth <= 900) return;
        const r = card.getBoundingClientRect();
        const x = (e.clientX - r.left) / r.width;
        const y = (e.clientY - r.top) / r.height;
        const rx = (0.5 - y) * 8;
        const ry = (x - 0.5) * 10;
        card.style.transform = `translateY(-6px) rotateX(${rx.toFixed(2)}deg) rotateY(${ry.toFixed(2)}deg)`;
      });
      card.addEventListener("mouseleave", () => {
        card.style.transform = "";
      });
    });
  }

  // --- Soft cursor glow (desktop) ---
  if (!reduce && fine) {
    const glow = document.createElement("div");
    glow.className = "cursor-glow";
    document.body.appendChild(glow);
    let gx = 0, gy = 0, tx = 0, ty = 0, on = false;
    window.addEventListener("mousemove", (e) => {
      tx = e.clientX;
      ty = e.clientY;
      if (!on) {
        on = true;
        glow.classList.add("is-on");
      }
    });
    window.addEventListener("mouseleave", () => {
      on = false;
      glow.classList.remove("is-on");
    });
    const tick = () => {
      gx += (tx - gx) * 0.12;
      gy += (ty - gy) * 0.12;
      glow.style.left = gx + "px";
      glow.style.top = gy + "px";
      requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  }

  // --- Ripple on buttons ---
  document.querySelectorAll("button").forEach((btn) => {
    btn.classList.add("pressable");
    btn.addEventListener("click", (e) => {
      if (btn.disabled || reduce) return;
      const r = btn.getBoundingClientRect();
      const span = document.createElement("span");
      const size = Math.max(r.width, r.height);
      span.style.cssText = `
        position:absolute;border-radius:50%;pointer-events:none;
        width:${size}px;height:${size}px;
        left:${e.clientX - r.left - size / 2}px;
        top:${e.clientY - r.top - size / 2}px;
        background:rgb(255 255 255 / 0.35);
        transform:scale(0);opacity:1;
        transition:transform .45s cubic-bezier(.22,1,.36,1), opacity .55s ease;
      `;
      btn.appendChild(span);
      requestAnimationFrame(() => {
        span.style.transform = "scale(2.2)";
        span.style.opacity = "0";
      });
      setTimeout(() => span.remove(), 550);
    });
  });

  // --- iOS back links ---
  document.querySelectorAll("[data-back]").forEach((link) => {
    link.addEventListener("click", (e) => {
      const fromSite = document.referrer && document.referrer.startsWith(window.location.origin);
      if (fromSite && window.history.length > 1) {
        e.preventDefault();
        window.history.back();
      }
    });
  });

  // --- Toast ---
  const params = new URLSearchParams(window.location.search);
  if (params.get("ok") === "1") {
    const toast = document.createElement("div");
    toast.className = "toast";
    toast.textContent = "Perubahan tersimpan";
    document.body.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add("show"));
    setTimeout(() => {
      toast.classList.remove("show");
      setTimeout(() => toast.remove(), 320);
    }, 2800);
  }

  // --- Focus polish ---
  document.querySelectorAll(".side-nav a, a.card, a.row, button, .out").forEach((el) => {
    el.addEventListener("focus", () => el.classList.add("is-focus"));
    el.addEventListener("blur", () => el.classList.remove("is-focus"));
  });

  // --- Quick-fill akun demo pada halaman masuk ---
  document.querySelectorAll("[data-fill-user]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const u = document.getElementById("login-user");
      const p = document.getElementById("login-pass");
      if (u && p) {
        u.value = btn.getAttribute("data-fill-user") || "";
        p.value = btn.getAttribute("data-fill-pass") || "";
        p.focus();
      }
    });
  });

  // --- Konfirmasi aksi sensitif ---
  document.querySelectorAll("form[data-confirm]").forEach((form) => {
    form.addEventListener("submit", (e) => {
      const msg = form.getAttribute("data-confirm");
      if (msg && !window.confirm(msg)) {
        e.preventDefault();
      }
    });
  });

  // --- Drawer menu kiri pada mobile & tablet ---
  const toggleBtn = document.querySelector(".nav-toggle");
  const closeBtn = document.querySelector(".drawer-close");
  const backdrop = document.querySelector(".drawer-backdrop");
  const setDrawer = (open) => {
    root.classList.toggle("nav-open", open);
    if (toggleBtn) {
      toggleBtn.setAttribute("aria-expanded", open ? "true" : "false");
    }
  };

  if (toggleBtn) {
    toggleBtn.addEventListener("click", () => {
      setDrawer(!root.classList.contains("nav-open"));
    });
  }
  if (closeBtn) {
    closeBtn.addEventListener("click", () => setDrawer(false));
  }
  if (backdrop) {
    backdrop.addEventListener("click", () => setDrawer(false));
  }
  document.querySelectorAll(".side-nav a").forEach((link) => {
    link.addEventListener("click", () => setDrawer(false));
  });
  window.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && root.classList.contains("nav-open")) {
      setDrawer(false);
    }
  });
  window.addEventListener("resize", () => {
    if (window.innerWidth > 900 && root.classList.contains("nav-open")) {
      setDrawer(false);
    }
  });

  // --- Live clock (format Indonesia, detik tabular, sinkron saat tab aktif) ---
  const clockEls = document.querySelectorAll("[data-live-clock]");
  if (clockEls.length > 0) {
    const idDays = ["Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"];
    const idMonths = ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"];
    const pad2 = (n) => String(n).padStart(2, "0");
    const getTzLabel = (d) => {
      const offMin = -d.getTimezoneOffset();
      if (offMin === 420) return "WIB";
      if (offMin === 480) return "WITA";
      if (offMin === 540) return "WIT";
      try {
        const parts = new Intl.DateTimeFormat("id-ID", { timeZoneName: "short" }).formatToParts(d);
        const tzPart = parts.find((p) => p.type === "timeZoneName");
        if (tzPart && tzPart.value) return tzPart.value;
      } catch (_) {}
      const sign = offMin >= 0 ? "+" : "-";
      const absH = Math.floor(Math.abs(offMin) / 60);
      return `GMT${sign}${absH}`;
    };

    let clockTimer = 0;
    const renderClocks = () => {
      const now = new Date();
      const dateStr = `${idDays[now.getDay()]}, ${now.getDate()} ${idMonths[now.getMonth()]} ${now.getFullYear()}`;
      const timeStr = `${pad2(now.getHours())}:${pad2(now.getMinutes())}:${pad2(now.getSeconds())}`;
      const isoStr = `${now.getFullYear()}-${pad2(now.getMonth() + 1)}-${pad2(now.getDate())}T${timeStr}`;
      const tzStr = getTzLabel(now);

      clockEls.forEach((el) => {
        const dateEl = el.querySelector("[data-clock-date]");
        const timeEl = el.querySelector("[data-clock-time]");
        const tzEl = el.querySelector("[data-clock-tz]");
        if (dateEl) dateEl.textContent = dateStr;
        if (timeEl) {
          timeEl.textContent = timeStr;
          timeEl.setAttribute("datetime", isoStr);
        }
        if (tzEl) tzEl.textContent = tzStr;
      });
    };

    const scheduleClock = () => {
      window.clearTimeout(clockTimer);
      renderClocks();
      const delay = 1000 - (Date.now() % 1000) + 12;
      clockTimer = window.setTimeout(scheduleClock, delay);
    };

    scheduleClock();
    document.addEventListener("visibilitychange", () => {
      if (!document.hidden) {
        scheduleClock();
      }
    });
  }

  // --- Pratinjau langsung foto profil sebelum diunggah ---
  const avatarInput = document.querySelector("[data-avatar-input]");
  const avatarPreview = document.querySelector("[data-avatar-preview]");
  const avatarNote = document.querySelector("[data-avatar-note]");
  if (avatarInput && avatarPreview) {
    let previewUrl = "";
    avatarInput.addEventListener("change", () => {
      const file = avatarInput.files && avatarInput.files[0];
      if (!file) return;
      if (file.size > 4 * 1024 * 1024) {
        if (avatarNote) {
          avatarNote.textContent = `Ukuran berkas "${file.name}" melebihi batas 4 MB. Pilih gambar yang lebih kecil.`;
        }
        avatarInput.value = "";
        return;
      }
      if (previewUrl) {
        URL.revokeObjectURL(previewUrl);
      }
      previewUrl = URL.createObjectURL(file);
      avatarPreview.innerHTML = "";
      const img = document.createElement("img");
      img.className = "avatar-img";
      img.src = previewUrl;
      img.alt = "Pratinjau foto profil";
      avatarPreview.appendChild(img);
      if (avatarNote) {
        avatarNote.textContent = `Pratinjau siap: ${file.name} — klik "Simpan Perubahan Profil" untuk menerapkan.`;
      }
    });
  }
})();
