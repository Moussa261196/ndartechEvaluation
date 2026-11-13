// Adaptateur : fournit à la page l’interface « claude.use(...) » branchée sur api.php (votre serveur).
// Prévu pour les hébergements gratuits avec contrôle navigateur (InfinityFree) : si la réponse n’est pas
// du JSON (page de vérification), le cookie est renouvelé via ping.html puis la requête est rejouée une fois.
(function () {
  var me = { role: "none", uid: null, name: null };
  function renewCookie() {
    return new Promise(function (res) {
      var f = document.createElement("iframe");
      f.style.cssText = "position:absolute;width:0;height:0;border:0";
      f.src = "ping.html"; document.body.append(f);
      setTimeout(function () { f.remove(); res(); }, 3000);
    });
  }
  function API(a, body, retried) {
    return fetch("api.php?a=" + a, {
      method: "POST", credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-Requested-With": "qcm" },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.text(); }).then(function (t) {
      try { return JSON.parse(t); }
      catch (e) {
        if (retried) throw new Error("reponse_invalide");
        return renewCookie().then(function () { return API(a, body, true); });
      }
    });
  }
  function poll(fetcher, cb, err) {
    var last = "", stop = false;
    (function tick() {
      if (stop) return;
      if (document.hidden) { setTimeout(tick, 3000); return; }
      fetcher().then(function (r) {
        var j = JSON.stringify(r);
        if (j !== last) { last = j; cb(r); }
      }).catch(function (e) { if (err) err(e); }).then(function () { setTimeout(tick, 10000); });
    })();
    return function () { stop = true; };
  }
  function doc(path) {
    function shape(r) { return { exists: !!r.exists, data: function () { return r.data; } }; }
    return {
      get: function () { return API("get", { path: path }).then(function (r) { if (r.error) throw new Error(r.error); return shape(r); }); },
      set: function (d) { return API("set", { path: path, data: d }).then(function (r) { if (r.error) throw new Error(r.error); }); },
      onSnapshot: function (cb, err) {
        return poll(function () { return API("get", { path: path }).then(function (r) { if (r.error) throw new Error(r.error); return r; }); },
          function (r) { cb(shape(r)); }, err);
      }
    };
  }
  function collection(path) {
    return {
      onSnapshot: function (cb, err) {
        return poll(function () { return API("list", { prefix: path }).then(function (r) { if (r.error) throw new Error(r.error); return r; }); },
          function (r) {
            var docs = r.rows.map(function (x) { return { id: x.path.split("/").pop(), data: function () { return x.data; } }; });
            cb({ docs: docs, size: docs.length });
          }, err);
      }
    };
  }
  window.API = API;
  window.claude = {
    refresh: function () { return API("whoami").then(function (r) { me = r; return r; }); },
    use: function (name) {
      if (name === "user") return Promise.resolve({ id: function () { return Promise.resolve(me.uid); }, isOwner: function () { return Promise.resolve(me.role === "admin"); } });
      if (name === "db") return Promise.resolve({ doc: doc, collection: collection });
      if (name === "downloads") return Promise.resolve({ save: function (o) {
        var a = document.createElement("a"); a.href = URL.createObjectURL(o.data); a.download = o.filename;
        document.body.append(a); a.click(); a.remove(); return Promise.resolve();
      } });
      return Promise.resolve(null);
    }
  };
})();
