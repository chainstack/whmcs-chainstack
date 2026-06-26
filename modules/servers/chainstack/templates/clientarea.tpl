{*
  Chainstack endpoints — client area view.
  Vars: $nodes, $serviceStatus, $iconBase, $fallbackIcon, optional $error.
*}
<div class="chainstack-endpoints">
    {if $error}
        <div class="alert alert-danger">{$error|escape}</div>
    {/if}

    {if $serviceStatus eq 'Suspended'}
        <div class="alert alert-warning">
            This service is suspended, so its endpoint has been removed. When the service is
            reactivated a <strong>new</strong> endpoint will be provisioned (the URL will differ
            from the previous one).
        </div>
    {elseif $nodes|count eq 0}
        <div class="alert alert-info">
            No endpoints yet. Your node may still be deploying — check back shortly or use
            <strong>Refresh Status</strong>.
        </div>
    {else}
        {foreach $nodes as $node}
            <div class="panel panel-default">
                <div class="panel-heading">
                    {if $node.protocol}<img src="{$iconBase}/{$node.protocol|escape:'url'}.svg"
                         alt="{$node.protocol|escape}" width="20" height="20"
                         style="vertical-align:middle;margin-right:6px"
                         onerror="this.onerror=null;this.src='{$fallbackIcon}'">{/if}
                    <strong>{$node.name|default:$node.id|escape}</strong>
                    {assign var="st" value=$node.status|lower}
                    {if $st eq 'running' or $st eq 'active'}
                        <span class="label label-success pull-right">{$node.status|escape}</span>
                    {elseif $st eq 'error' or $st eq 'failed'}
                        <span class="label label-danger pull-right">{$node.status|escape}</span>
                    {else}
                        <span class="label label-warning pull-right">{$node.status|escape}</span>
                    {/if}
                </div>
                <div class="panel-body">
                    {if $node.https}
                        <div class="form-group">
                            <label>HTTPS endpoint</label>
                            <input type="text" class="form-control" readonly
                                   value="{$node.https|escape}" onclick="this.select();">
                        </div>
                    {/if}
                    {if $node.wss}
                        <div class="form-group">
                            <label>WSS endpoint</label>
                            <input type="text" class="form-control" readonly
                                   value="{$node.wss|escape}" onclick="this.select();">
                        </div>
                    {/if}
                    {if $node.beacon}
                        <div class="form-group">
                            <label>Beacon endpoint</label>
                            <input type="text" class="form-control" readonly
                                   value="{$node.beacon|escape}" onclick="this.select();">
                        </div>
                    {/if}
                    {if $node.namespaces}
                        <p><small>API namespaces: {', '|implode:$node.namespaces|escape}</small></p>
                    {/if}
                    {if $st eq 'error' or $st eq 'failed'}
                        <p class="text-danger">This node failed to deploy. Please contact support.</p>
                    {elseif $st neq 'running' and $st neq 'active'}
                        <p class="text-muted">This node is being provisioned; endpoints appear once it is running.</p>
                    {/if}
                </div>
            </div>
        {/foreach}
    {/if}
</div>
