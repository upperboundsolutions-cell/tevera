<div class="fleet-grid"><label>Vehicle limit<input type="number" name="vehicle_limit" min="1" max="1000000" value="{{ old('vehicle_limit') }}" placeholder="Unlimited"></label></div>
<h3>Company administrator</h3><p class="muted">Create the first administrator now, or leave all three fields blank and add one later in Users & access.</p>
<div class="fleet-grid">
<label>Administrator name<input name="admin_name" maxlength="255" value="{{ old('admin_name') }}" autocomplete="off"></label>
<label>Administrator email<input name="admin_email" type="email" maxlength="255" value="{{ old('admin_email') }}" autocomplete="off"></label>
<label>Password<input name="admin_password" type="password" autocomplete="new-password" minlength="12" maxlength="1024"></label>
<label>Confirm password<input name="admin_password_confirmation" type="password" autocomplete="new-password" maxlength="1024"></label>
</div><p class="muted">Use at least 12 characters with uppercase, lowercase, numbers and symbols. Share credentials through a secure channel.</p>
